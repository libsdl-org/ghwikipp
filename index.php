<?php

require_once('config.php');

$supported_formats = [
    'md' => 'gfm',
    'mediawiki' => 'mediawiki'
];

$github_url = "https://github.com/$github_repo_owner/$github_repo";
$document = NULL;  // !!! FIXME: remove this global.
$operation = NULL;

putenv("GIT_SSH_COMMAND=ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i $ssh_key_fname");
putenv("GIT_COMMITTER_NAME=$git_committer_name");
putenv("GIT_COMMITTER_EMAIL=$git_committer_email");
putenv("CSEARCHINDEX=$cooked_data/.csearchindex");


$git_repo_lock_fp = false;
$git_repo_lock_count = 0;
function obtain_git_repo_lock()
{
    global $repo_lock_fname, $git_repo_lock_fp, $git_repo_lock_count;

    //$starttime = microtime(true);

    if ( ($git_repo_lock_count < 0) ||
         (($git_repo_lock_fp === false) && ($git_repo_lock_count != 0)) ||
         (($git_repo_lock_fp !== false) && ($git_repo_lock_count == 0)) ) {
        fail503('Git repo lock confusion! This is a bug. Please try again later.');
    }

    if ($git_repo_lock_count == 0) {
        $git_repo_lock_fp = fopen($repo_lock_fname, 'c+');
        if ($git_repo_lock_fp === false) {
            fail503("Failed to obtain Git repo lock. Please try again later.");
        } else if (flock($git_repo_lock_fp, LOCK_EX) === false) {
            fail503("Exclusive flock of Git repo lock failed. Please try again later.");  // uh...?
        }
    }

    $git_repo_lock_count++;

    //$totaltime = (int) (microtime(true) - $starttime);
    //print("\n\n\n\n\nTime to obtain git repo lock: $totaltime seconds.\n\n\n\n");
}

function release_git_repo_lock()
{
    global $git_repo_lock_fp, $git_repo_lock_count;
    if (($git_repo_lock_fp === false) || ($git_repo_lock_count <= 0)) {
        fail503('Git repo unlocked too many times! This is a bug. Please try again later.');
    }

    --$git_repo_lock_count;
    if ($git_repo_lock_count == 0) {
        flock($git_repo_lock_fp, LOCK_UN);
        fclose($git_repo_lock_fp);
        $git_repo_lock_fp = false;
    }
}


function get_template($template, $vars=NULL, $safe_to_fail=true)
{
    global $document, $operation, $wikiname, $wikidesc, $wiki_license, $wiki_license_url, $twitteruser;
    global $github_repo_main_branch, $base_url, $github_url, $wiki_offline_basename;

    $str = file_get_contents($template);
    if ($str === false) {
        if ($safe_to_fail) {  // don't call fail503 if we'd infinitely recurse.
            fail503("Internal error: missing template '$template'");
        } else {
            header('HTTP/1.0 503 Service Unavailable');
            header('Content-Type: text/plain; charset=utf-8');
            print("\n\nERROR: Missing template '$template' while already failing, giving up.\n\n");
            exit(0);
        }
    }

    // let templates include common text.
    $str = preg_replace_callback(
               '/\@include (.*?)\@/',
               function ($matches) use ($vars, $safe_to_fail) {
                   return get_template('templates/' . $matches[1], $vars, $safe_to_fail);
               },
               $str
           );

    // replace any @varname@ with the value of that variable.
    return preg_replace_callback(
               '/\@([a-z_]+)\@/',
               function ($matches) use (
                       $vars, $document, $operation, $wikiname, $wikidesc,
                       $wiki_license, $wiki_license_url, $base_url, $github_url,
                       $twitteruser, $github_repo_main_branch, $wiki_offline_basename) {
                   $key = $matches[1];
                   if (($vars != NULL) && isset($vars[$key])) { return $vars[$key]; }
                   else if ($key == 'page') { return $document; }
                   else if ($key == 'operation') { return $operation; }
                   else if ($key == 'url') { return $_SERVER['PHP_SELF']; }
                   else if ($key == 'domain') { return $_SERVER['SERVER_NAME']; }
                   else if ($key == 'baseurl') { return $base_url; }
                   else if ($key == 'github_id') { return isset($_SESSION['github_id']) ? $_SESSION['github_id'] : '[not logged in]'; }
                   else if ($key == 'github_user') { return isset($_SESSION['github_user']) ? $_SESSION['github_user'] : '[not logged in]'; }
                   else if ($key == 'github_name') { return isset($_SESSION['github_name']) ? $_SESSION['github_name'] : '[not logged in]'; }
                   else if ($key == 'github_email') { return isset($_SESSION['github_email']) ? $_SESSION['github_email'] : '[not logged in]'; }
                   else if ($key == 'github_avatar') { return isset($_SESSION['github_avatar']) ? $_SESSION['github_avatar'] : ''; }  // !!! FIXME: return a generic image instead of ''
                   else if ($key == 'wikiname') { return $wikiname; }
                   else if ($key == 'wikidesc') { return $wikidesc; }
                   else if ($key == 'wikilicense') { return $wiki_license; }
                   else if ($key == 'wikilicenseurl') { return $wiki_license_url; }
                   else if ($key == 'twitteruser') { return $twitteruser; }
                   else if ($key == 'githuburl') { return $github_url; }
                   else if ($key == 'githubmainbranch') { return $github_repo_main_branch; }
                   else if ($key == 'offlinebasename') { return $wiki_offline_basename; }
                   else if ($key == 'title') { return "$document - $wikiname"; }  // this is often overridden by $vars.
	           return "@$key@";  // ignore it.
               },
               $str
           );
}

function print_template($template, $vars=NULL, $safe_to_fail=true)
{
    print(get_template("templates/$template", $vars, $safe_to_fail));
}


function fail($response, $msg, $url=NULL, $template='fail', $extravars=NULL)
{
    global $git_repo_lock_fp;
    if ($git_repo_lock_fp !== false) {  // make sure we're definitely released.
        flock($git_repo_lock_fp, LOCK_UN);
        fclose($git_repo_lock_fp);
        $git_repo_lock_fp = false;
    }

    if ($response != NULL) {
        header("HTTP/1.0 $response");
        if ($url != NULL) { header("Location: $url"); }
    }

    $vars = [ 'httpresponse' => $response, 'message' => $msg ];

    if ($url != NULL) {
        $vars['url'] = $url;
    }

    if ($extravars != NULL) {
        foreach($extravars as $k => $v) {
            $vars[$k] = $v;
        }
    }

    print_template($template, $vars, false);
    exit(0);
}

function fail400($msg, $template='fail', $extravars=NULL) { fail('400 Bad Request', $msg, NULL, $template, $extravars); }
function fail404($msg, $template='fail', $extravars=NULL) { fail('404 Not Found', $msg, NULL, $template, $extravars); }
function fail503($msg, $template='fail', $extravars=NULL) { fail('503 Service Unavailable', $msg, NULL, $template, $extravars); }

function redirect($url, $msg=NULL)
{
    if ($msg == NULL) {
        $msg = "We need your browser to go here now: <a href='$url'>$url</a>";
    }
    fail('302 Found', $msg, $url, 'redirect');
}

function require_session()
{
    if (!session_id()) {
        if (!session_start()) {
            fail503('Session failed to start, please try again later.');
        }
    }
}

function cook_string($str, $from_pandoc_format)
{
    if ($from_pandoc_format == NULL) {
        return "<pre>\n$str\n</pre>\n";  // oh well.
    }

    $descriptorspec = array(
        0 => array('pipe', 'r'),  // stdin
        1 => array('pipe', 'w'),  // stdout
    );

    $proc = proc_open([ 'pandoc', '-f', $from_pandoc_format, '-t', 'html', '--toc', '--lua-filter=./pandoc-filter.lua' ], $descriptorspec, $pipes);

    $retval = '';
    if (is_resource($proc)) {
        fwrite($pipes[0], $str);
        fclose($pipes[0]);
        $retval = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($proc);
    }

    return $retval;
}

function cook_page_from_files($rawfname, $cookedfname)
{
    global $supported_formats;

    $cooked = NULL;

    $from_format = NULL;
    $ext = strrchr($rawfname, '.');
    if ($ext !== false) {
        $ext = substr($ext, 1);
        if (isset($supported_formats[$ext])) {
            $from_format = $supported_formats[$ext];
        }
    }

    obtain_git_repo_lock();
    if (!file_exists($rawfname)) {  // was deleted?
        @unlink($cookedfname);
    } else {
        $raw = file_get_contents($rawfname);
        if ($raw === false) {
            fail503("Failed to read $rawfname to cook it. Please try again later.");
        }
        $cooked = cook_string($raw, $from_format);
        if (($cooked != '') || ($raw == '')) {
            @mkdir(dirname($cookedfname));
            $tmppath = "$cookedfname.tmp";
            if (file_put_contents($tmppath, $cooked) != strlen($cooked)) {
                fail503("Failed to write $cookedfname when cooking. Please try again later.");
            } else if (!rename($tmppath, $cookedfname)) {
                unlink($tmppath);
                fail503("Failed to rename $tmppath to $cookedfname when cooking. Please try again later.");
            }
        }
    }
    release_git_repo_lock();

    return $cooked;
}

function cook_page($page)
{
    global $raw_data, $cooked_data, $supported_formats;

    $dst = "$cooked_data/$page.html";
    foreach ($supported_formats as $ext => $format) {
        $src = "$raw_data/$page.$ext";
        if (file_exists($src)) {
            return cook_page_from_files($src, $dst);
        }
    }

    fail503("Internal error: Couldn't find page's file to cook!");
}

function recook_tree($rawbasedir, $cookedbasedir, $dir, &$updated)
{
    global $supported_formats;

    $dirp = opendir("$rawbasedir/$dir");
    if ($dirp === false) {
        return;
    }

    while (($dent = readdir($dirp)) !== false) {
        if (substr($dent, 0, 1) == '.') { continue; }  // skip ".", "..", and metadata.
        $page = ($dir == '') ? $dent : "$dir/$dent";
        if (is_dir("$rawbasedir/$page")) {
            recook_tree($rawbasedir, $cookedbasedir, $page, $updated);
        } else {
            $src = "$rawbasedir/$page";
            $dst = "$cookedbasedir/$page";
            $dst = preg_replace('/^(.*)\..*?$/', '$1.html', $dst);
            $srcmtime = filemtime($src);
            $dstmtime = @filemtime($dst);
            if (($srcmtime === false) || ($dstmtime === false) || ($srcmtime >= $dstmtime)) {
                cook_page_from_files($src, $dst);
                $updated[] = "+ $page";
            }
        }
    }
    closedir($dirp);

    // prune cooked dir...
    $dirp = opendir("$cookedbasedir/$dir");
    if ($dirp === false) {
        return;
    }

    while (($dent = readdir($dirp)) !== false) {
        if (substr($dent, 0, 1) == '.') { continue; }  // skip ".", "..", and metadata.
        $page = ($dir == '') ? $dent : "$dir/$dent";
        $dst = "$cookedbasedir/$page";
        $src = "$rawbasedir/$page";
        $src = preg_replace('/\.html$/', '', $src);
        $found = false;
        foreach ($supported_formats as $ext => $format) {
            if (file_exists("$src.$ext")) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            if (is_dir($dst)) {
                @rmdir($dst);  // we don't recurse here, since we're recursing above.
            } else {
                unlink($dst);
            }
            $updated[] = "- $page";
        }
    }
    closedir($dirp);
}

function recook_search_index()
{
    global $raw_data;
    unset($output);
    $escrawdata = escapeshellarg($raw_data);
    $failed = (exec("cindex $escrawdata", $output, $result) === false) || ($result != 0);
    // !!! FIXME: check for failure here.
}


// Convert any relative links in a wiki page to full links. You only want to do this for
// throwaway cooks for preview content while editing, since that's running off a subdir
// of where the edited page would normally be, which messes up links.
function fixup_preview_links($page, $data)
{
    global $base_url;

    $stripped_page = dirname($page);  // this just happens to work.
    $pattern = '/(\<a href=")((?![a-z]*\:\/\/|\/)(.*?))("\>)/i';
    $replacement = '$1' . "$base_url/$stripped_page/" . '$3$4';
    return preg_replace($pattern, $replacement, $data);
}


// Stole some of this from https://github.com/dintel/php-github-webhook/blob/master/src/Handler.php
function validate_webhook_signature($gitHubSignatureHeader, $payload)
{
    global $github_webhook_secret;

    list ($algo, $gitHubSignature) = explode("=", $gitHubSignatureHeader);

    if (($algo !== 'sha256') && ($algo !== 'sha1')) {
        // see https://developer.github.com/webhooks/securing/
        return false;
    }

    $payloadHash = hash_hmac($algo, $payload, $github_webhook_secret);
    return ($payloadHash === $gitHubSignature);
}

function github_webhook()
{
    global $raw_data, $cooked_data, $github_repo_main_branch;

    header('Content-Type: text/plain; charset=utf-8');

    $str = '';

    $signature = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : NULL;
    $event = isset($_SERVER['HTTP_X_GITHUB_EVENT']) ? $_SERVER['HTTP_X_GITHUB_EVENT'] : NULL;
    $delivery = isset($_SERVER['HTTP_X_GITHUB_DELIVERY']) ? $_SERVER['HTTP_X_GITHUB_DELIVERY'] : NULL;

    if (!isset($signature, $event, $delivery)) {
        fail400("$str\nBAD SIGNATURE\n", 'fail_plaintext');
    }

    $starttime = microtime(true);
    $payload = file_get_contents('php://input');
    $totaltime = (int) (microtime(true) - $starttime);
    $str .= "OBTAINED JSON DATA IN $totaltime SECONDS.\n";

    $starttime = microtime(true);

    // Check if the payload is json or urlencoded.
    if (strpos($payload, 'payload=') === 0) {
        $payload = substr(urldecode($payload), 8);
    }

    if (!validate_webhook_signature($signature, $payload)) {
        fail400("$str\nBAD SIGNATURE\n", 'fail_plaintext');
    }

    $totaltime = (int) (microtime(true) - $starttime);
    $str .= "VALIDATED WEBHOOK SIGNATURE IN $totaltime SECONDS.\n";

    $str .= "SIGNATURE OK IN $totaltime SECONDS.\n";

    $str .= "GITHUB EVENT: $event\n";

    if ($event == 'push') {   // we only care about push events.
        $json = json_decode($payload, TRUE);
        if (($json['ref'] != "refs/heads/$github_repo_main_branch") && $json['deleted']) {
            $str .= "PUSH EVENT IS A BRANCH DELETION, DOING A FETCH FOR PRUNING.\n";  // probably a topic branch we just pushed for a pull request.
            $starttime = microtime(true);
            obtain_git_repo_lock();
            $totaltime = (int) (microtime(true) - $starttime);
            $str .= "OBTAINED GIT REPO LOCK IN $totaltime SECONDS.\n";

            $starttime = microtime(true);
            $escrawdata = escapeshellarg($raw_data);
            $cmd = "( cd $escrawdata && git fetch --prune ) 2>&1";
            unset($output);
            $failed = (exec($cmd, $output, $result) === false) || ($result != 0);

            $str .= "\nOUTPUT of '$cmd':\n\n";
            foreach ($output as $l) {
                $str .= "    $l\n";
            }

            if ($failed) {
                fail503("$str\nGIT FETCH FAILED!\n", 'fail_plaintext');
            }

            $totaltime = (int) (microtime(true) - $starttime);
            $str .= "GIT FETCH COMPLETE IN $totaltime SECONDS.\n";

            $starttime = microtime(true);
            $escrawdata = escapeshellarg($raw_data);
            $branch = preg_replace('/^refs\/heads\//', '', $json['ref']);
            $escbranch = escapeshellarg($branch);
            $cmd = "( cd $escrawdata && git branch -D $escbranch ) 2>&1";
            unset($output);
            $failed = (exec($cmd, $output, $result) === false) || ($result != 0);

            $str .= "\nOUTPUT of '$cmd':\n\n";
            foreach ($output as $l) {
                $str .= "    $l\n";
            }

            if ($failed) {
                fail503("$str\nGIT BRANCH DELETE FAILED!\n", 'fail_plaintext');
            }

            $totaltime = (int) (microtime(true) - $starttime);
            $str .= "GIT BRANCH DELETE COMPLETE IN $totaltime SECONDS.\n";
        } else if ($json['ref'] != "refs/heads/$github_repo_main_branch") {
            $str .= "PUSH EVENT ISN'T FOR MAIN BRANCH, IGNORING.\n";  // probably a topic branch we just pushed for a pull request.
        } else {
            $starttime = microtime(true);
            obtain_git_repo_lock();
            $totaltime = (int) (microtime(true) - $starttime);
            $str .= "OBTAINED GIT REPO LOCK IN $totaltime SECONDS.\n";

            $starttime = microtime(true);
            $escrawdata = escapeshellarg($raw_data);
            $cmd = "( cd $escrawdata && git pull --rebase --prune ) 2>&1";
            unset($output);
            $failed = (exec($cmd, $output, $result) === false) || ($result != 0);

            $str .= "\nOUTPUT of '$cmd':\n\n";
            foreach ($output as $l) {
                $str .= "    $l\n";
            }

            if ($failed) {
                fail503("$str\nGIT PULL FAILED!\n", 'fail_plaintext');
            }

            $totaltime = (int) (microtime(true) - $starttime);
            $str .= "GIT PULL COMPLETE IN $totaltime SECONDS.\n";

            $starttime = microtime(true);
            $updated = array();
            $failed = false; // !!! FIXME: recook_tree returns void atm
            recook_tree($raw_data, $cooked_data, '', $updated);

            $str .= "\n";
            foreach ($updated as $f) {
                $str .= "$f\n";
            }
            $str .= "\n";

            if ($failed) {
                fail503("$str\nFAILED TO RECOOK DATA!\n", 'fail_plaintext');
            }

            recook_search_index();

            $totaltime = (int) (microtime(true) - $starttime);

            release_git_repo_lock();

            $str .= "\nTREE RECOOKED IN $totaltime SECONDS.\n";
        }
    }

    // handle other events we care about here.

    print("$str\n\nOK\n\n");

    exit(0);   // never return from this function.
}


function handle_oauth_error()
{
    if (isset($_REQUEST['error'])) {  // this is probably a failure from GitHub's OAuth page.
        require_session();
        if (isset($_SESSION['github_oauth_state'])) { unset($_SESSION['github_oauth_state']); }
        $reason = isset($_REQUEST['error_description']) ? $_REQUEST['error_description'] : 'unknown error';
        fail400('GitHub auth error', $template='auth_error', [ 'error' => $_REQUEST['error'], 'reason' => $reason ]);
    }
}

function call_github_api($url, $args=NULL, $token=NULL, $ispost=false, $failonerror=true)
{
    global $user_agent;

    if (!$ispost && ($args != NULL) && (!empty($args)) ) {
        $url .= '?' . http_build_query($args);
    }

    $reqheaders = array(
        "User-Agent: $user_agent",
        'Accept: application/json'
    );

    if ($token != NULL) {
        $reqheaders[] = 'Authorization: token ' . $token;
    }

    if ($ispost) {
        $reqheaders[] = 'Content-Type: application/json; charset=utf-8';
    }

    $curl = curl_init($url);
    $curlopts = array(
        CURLOPT_AUTOREFERER => TRUE,
        CURLOPT_FOLLOWLOCATION => TRUE,
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_HTTPHEADER => $reqheaders
    );

    if ($ispost) {
        $curlopts[CURLOPT_POST] = TRUE;
        $curlopts[CURLOPT_POSTFIELDS] = json_encode(($args == NULL) ? array() : $args);
    }

     //print("CURLOPTS:\n"); print_r($curlopts);
     //print("REQHEADERS:\n"); print_r($reqheaders);

     curl_setopt_array($curl, $curlopts);
     $responsejson = curl_exec($curl);
     $curlfailed = ($responsejson === false);
     $curlerr = $curlfailed ? curl_error($curl) : NULL;
     $httprc = $curlfailed ? 0 : curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
     curl_close($curl);
     unset($curl);

     //print("RESPONSE:\n"); print_r(json_decode($responsejson, TRUE));

     if ($curlfailed) {
         fail503("Couldn't talk to GitHub API, try again later ($curlerr)");
     } else if (($httprc != 200) && ($httprc != 201)) {
         if (!$failonerror) {
             return NULL;
         }
         fail503("GitHub API reported error $httprc, try again later");
     }

     $retval = json_decode($responsejson, TRUE);
     if ($retval == NULL) {
         fail503('GitHub API sent us bogus JSON, try again later.');
     }
     return $retval;
}

function endsWith($haystack, $needle)
{
    $length = strlen($needle);
    return $length > 0 ? substr($haystack, -$length) === $needle : true;
}

function authorize_with_github($force=false)
{
    global $github_oauth_clientid;
    global $github_oauth_secret;
    global $blocked_data, $trusted_data, $admin_data, $always_admin, $base_url;

    require_session();

    handle_oauth_error();  // die now if we think GitHub refused to auth user.

    //print_r($_SESSION);

    // $force==true means we're being asked to make sure GitHub is still cool
    //  with this session, which we do before requests that make changes, like
    //  committing an edit. If the user auth'd with GitHub but then logged
    //  out over there since our last check, we might be letting the user do
    //  things they shouldn't be allowed to do anymore. For the basic workflow
    //  we don't care if they are still _actually_ logged in at every step,
    //  though.

    if ( !$force &&
         isset($_SESSION['github_id']) &&
         isset($_SESSION['github_user']) &&
         isset($_SESSION['github_email']) &&
         isset($_SESSION['github_name']) &&
         isset($_SESSION['github_access_token']) &&
         isset($_SESSION['last_auth_time']) &&
         isset($_SESSION['expected_ipaddr']) &&
         isset($_SESSION['is_blocked']) &&
         isset($_SESSION['is_trusted']) &&
         isset($_SESSION['is_admin']) &&
         ( (time() - $_SESSION['last_auth_time']) < (60 * 60) ) &&
         ($_SESSION['expected_ipaddr'] == $_SERVER['REMOTE_ADDR']) ) {
        //print("ALREADY LOGGED IN\n");

        if ($_SESSION['is_blocked']) {
            fail400('Sorry, but you are blocked from this wiki.');
        }
        return;  // we're already logged in.

    // if there's a "code" arg, this is probably a redirect back from GitHub's OAuth page.
    //  make sure this isn't the user just reloading the page before using it again, as that will fail; we'll reauth to get a new code in that case.
    } else if ( isset($_REQUEST['code']) && (!isset($_SESSION['github_last_used_oauth_code']) || ($_SESSION['github_last_used_oauth_code'] != $_REQUEST['code'])) ) {
        if ( !isset($_REQUEST['state']) || !isset($_SESSION['github_oauth_state']) ) {
            fail400('GitHub authorization appears to be confused, please try again.');
        } else if ($_SESSION['github_oauth_state'] != $_REQUEST['state']) {
            fail400('This appears to be a bogus attempt to authorize with GitHub. If in error, please try again.');
        }

        $_SESSION['github_last_used_oauth_code'] = $_REQUEST['code'];

        $tokenurl = 'https://github.com/login/oauth/access_token';
        $response = call_github_api($tokenurl, [
            'client_id' => $github_oauth_clientid,
            'client_secret' => $github_oauth_secret,
            'state' => $_REQUEST['state'],
            'code' => $_REQUEST['code']
        ]);

        //print("\n<pre>\n"); print_r($response); print("\n</pre>\n\n");
        if (!isset($response['access_token'])) {
            fail503("GitHub OAuth didn't provide an access token! Please try again later.");
        }

        $token = $response['access_token'];
        $_SESSION['github_access_token'] = $token;
    }

    // If we already have an access token (or just got one, above), see if
    //  it's still valid. If it isn't, force a full reauth. If it still
    //  works, GitHub still thinks we're cool.
    if (isset($_SESSION['github_access_token']))
    {
        $response = call_github_api('https://api.github.com/user', NULL, $_SESSION['github_access_token'], false, false);

        if ($response != NULL) {
            //print("\n<pre>\nGITHUB USER API RESPONSE:\n"); print_r($response); print("\n</pre>\n\n");

            if ( !isset($response['name']) ) {
                $response['name'] = $response['login'];  // real name not given, just use the username instead.
            }

            if ( !isset($response['id']) ||
                 !isset($response['login']) ||
                 !isset($response['name']) ) {
                $msg = "GitHub didn't tell us everything we need to know about you to use this wiki. Please try again later.<br/><br/>\n" .
                       "<pre>some debug info...\n\n" .
                       "Response from GitHub:\n" . print_r($response, true) . "\n\n\n" .
                       "\$_SESSION:\n" . print_r($_SESSION, true) . "\n\n\n" .
                       "</pre>\n<br/>\n" .
                       "You can report this bug at the <a href='https://github.com/libsdl-org/ghwikipp/issues/new'>bug tracker</a>" .
                       " or privately to <a href='mailto:icculus@icculus.org?subject=ghwikipp%20login%20bug'>Ryan's email</a>.\n";
                unset($_SESSION['github_access_token']);
                fail503($msg);
            }

            // Find a public email, favoring the one marked "primary" if possible.
            $emailresponse = call_github_api('https://api.github.com/user/emails', NULL, $_SESSION['github_access_token'], false, false);
            //print("\n<pre>\nGITHUB USER EMAIL API RESPONSE:\n"); print_r($emailresponse); print("\n</pre>\n\n");

            $fakeemail = NULL;
            $bestemail = NULL;
            if (is_array($emailresponse)) {
                foreach ($emailresponse as $e) {
                    if (!isset($e['email'])) { continue; }
                    if (isset($e['visibility']) && ($e['visibility'] == 'private')) { continue; }
                    if ($bestemail == NULL) { $bestemail = $e['email']; }
                    if (isset($e['primary']) && (((int) $e['primary']) == 1)) { $bestemail = $e['email']; }
                    if (endsWith($e['email'], '@users.noreply.github.com')) { $fakeemail = $e['email']; }
                }
            }

            if ($bestemail == NULL) {
                $bestemail = $fakeemail;
            }

            if ($bestemail == NULL) {
                if (isset($response['email'])) {  // take the one from the initial user request, which isn't always set for some reason.
                    $bestemail = $response['email'];
                } else {
                    $msg = "GitHub won't tell us your email address, which we need to make edits to the wiki. Check your settings on GitHub?<br/><br/>\n" .
                           "<pre>some debug info...\n\n" .
                           "Response from GitHub/user:\n" . print_r($response, true) . "\n\n\n" .
                           "Response from GitHub/user/emails:\n" . print_r($emailresponse, true) . "\n\n\n" .
                           "\$_SESSION:\n" . print_r($_SESSION, true) . "\n\n\n" .
                           "</pre>\n<br/>\n" .
                           "You can report this bug at the <a href='https://github.com/libsdl-org/ghwikipp/issues/new'>bug tracker</a>" .
                           " or privately to <a href='mailto:icculus@icculus.org?subject=ghwikipp%20login%20bug'>Ryan's email.</a>\n";
                    unset($_SESSION['github_access_token']);
                    fail503($msg);
                }
            }

            //print("\n<pre>\nWe think your email address is $bestemail\n</pre>\n\n");

            $_SESSION['expected_ipaddr'] = $_SERVER['REMOTE_ADDR'];
            $_SESSION['last_auth_time'] = time();
            $_SESSION['github_id'] = $response['id'];
            $_SESSION['github_user'] = $response['login'];
            $_SESSION['github_email'] = $bestemail;
            $_SESSION['github_name'] = $response['name'];
            $_SESSION['github_avatar'] = isset($response['avatar_url']) ? $response['avatar_url'] : '';
            $_SESSION['is_blocked'] = file_exists("$blocked_data/" . $response['id']);
            $_SESSION['is_trusted'] = file_exists("$trusted_data/" . $response['id']);
            $_SESSION['is_admin'] = file_exists("$admin_data/" . $response['id']);

            if (!$_SESSION['is_admin'] && isset($always_admin)) {
                $thisuser = $_SESSION['github_user'];
                if (is_array($always_admin)) {
                    foreach ($always_admin as $u) {
                        if ($u == $thisuser) {
                            $_SESSION['is_admin'] = 1;
                            break;
                        }
                    }
                } else {
                    $_SESSION['is_admin'] = ($thisuser == $always_admin);
                }
            }

            //print("SESSION:\n"); print_r($_SESSION);

            if ($_SESSION['is_blocked']) {
                fail400('Sorry, but you are blocked from this wiki.');
            }

            //print("AUTH TO GITHUB VALID AND READY\n");
            // logged in, verified, and good to go!

            if (isset($_REQUEST['code'])) {   //Force a redirect to dump the code and state URL args.
                redirect($_SERVER['PHP_SELF'], 'Reloading to finish login!');
            }

            return;  // logged in and ready to go as-is.
        }

        // still here? We'll try reauthing, below.
    }


    // we're starting authorization from scratch.

    // kill any lingering state.
    unset($_SESSION['github_id']);
    unset($_SESSION['github_user']);
    unset($_SESSION['github_email']);
    unset($_SESSION['github_name']);
    unset($_SESSION['github_avatar']);
    unset($_SESSION['github_access_token']);
    unset($_SESSION['expected_ipaddr']);
    unset($_SESSION['last_auth_time']);
    unset($_SESSION['is_blocked']);
    unset($_SESSION['is_trusted']);
    unset($_SESSION['is_admin']);

    // !!! FIXME: no idea if this is a good idea.
    $_SESSION['github_oauth_state'] = hash('sha256', microtime(TRUE) . rand() . $_SERVER['REMOTE_ADDR']);

    $github_authorize_url = 'https://github.com/login/oauth/authorize';
    redirect($github_authorize_url . '?' . http_build_query([
        'client_id' => $github_oauth_clientid,
        'redirect_uri' => $base_url . $_SERVER['PHP_SELF'],
        'state' => $_SESSION['github_oauth_state'],
        'scope' => 'user:email'
    ]), 'Sending you to GitHub to log in...');

    // if everything goes okay, GitHub will redirect the user back to our
    //  same URL and we can try again, this time with authorization.
}

function force_authorize_with_github()
{
    authorize_with_github(true);
}

function calculate_branch_name($document, $userid)
{
    return "edit-$userid-$document";
}

function find_existing_pull_request($document, $userid)
{
    global $raw_data;
    $branch = calculate_branch_name($document, $userid);
    $escbranch = escapeshellarg("refs/heads/$branch");
    $escrawdata = escapeshellarg($raw_data);
    $cmd = "cd $escrawdata && git show-ref --verify --quiet $escbranch";
    unset($output);
    $failed = (exec($cmd, $output, $result) === false);
    if ($failed) {
        fail503('Failed to lookup existing edit branch in git; please try again later.');
    }
    return ($result == 0) ? $branch : NULL;
}


function make_new_page_version($page, $ext, $newtext, $comment)
{
    global $raw_data, $git_commit_message_file, $git_committer_user, $github_url, $base_url;
    global $github_committer_token, $github_repo_owner, $github_repo, $supported_formats;
    global $github_repo_main_branch;

    force_authorize_with_github();  // only returns if we are authorized.

    $newtext = str_replace("\r\n", "\n", $newtext);
    $comment = str_replace("\r\n", "\n", $comment);

    $gitfname = "$raw_data/$page.$ext";
    $escpage = escapeshellarg("$page.$ext");
    $escrawdata = escapeshellarg($raw_data);

    $escmain = escapeshellarg($github_repo_main_branch);
    $trusted_author = $_SESSION['is_trusted'] || $_SESSION['is_admin'];   // trusted/admin authors push right to main. Untrusted authors generate pull requests.
    $author = $_SESSION['github_name'] . ' <' . $_SESSION['github_email'] . '>';
    $escauthor = escapeshellarg($author);
    $escmsgfile = escapeshellarg($git_commit_message_file);

    if ($_SESSION['github_user'] == 'icculus') { $trusted_author = false; }  // uncomment for debugging purposes.

    obtain_git_repo_lock();

    $existing_branch = find_existing_pull_request($page, $_SESSION['github_id']);
    $branch = $existing_branch;
    if ($existing_branch == NULL) {
        $branch = calculate_branch_name($page, $_SESSION['github_id']);
        $escbranch = escapeshellarg($branch);
    } else {
        $escbranch = escapeshellarg($branch);
        $cmd = "( cd $escrawdata && git checkout $escbranch ) 2>&1";
        unset($output);
        if ((exec($cmd, $output, $result) === false) || ($result != 0)) {
            exec("cd $escrawdata && git checkout $escmain");
            fail503('Failed to checkout topic branch from git. Please try again later.');
        }
    }

    if ($comment == '') {
        $comment = is_readable($gitfname) ? 'Updated.' : 'Added.';
    }

    $comment = "$page: $comment";
    $full_comment = "$comment\n\nLive page is here: $base_url/$page\n\n";

    if (file_put_contents($git_commit_message_file, $full_comment) != strlen($full_comment)) {
        unlink($git_commit_message_file);  // just in case.
        exec("cd $escrawdata && git checkout $escmain");
        fail503('Failed to write new content to disk. Please try again later.');
    }

    @mkdir(dirname($gitfname));
    if (file_put_contents($gitfname, $newtext) != strlen($newtext)) {
        unlink($git_commit_message_file);
        exec("cd $escrawdata && git checkout -- $escpage && git checkout $escmain");
        fail503('Failed to write new content to disk. Please try again later.');
    }

    // remove files if the file extension changed.
    $rmcmd = '';
    foreach ($supported_formats as $findext => $format) {
        if ($ext != $findext) {
            $rmpage = "$page.$findext";
            if (file_exists("$raw_data/$rmpage")) {
                $escrmpage = escapeshellarg($rmpage);
                $rmcmd .= " && git rm $escrmpage";
            }
        }
    }

    $hash = '';
    $cmd = '';

    if ($existing_branch != NULL) {
        $cmd = "( cd $escrawdata && git add $escpage $rmcmd && git commit -F $escmsgfile --author=$escauthor && git push && git checkout $escmain) 2>&1";
    } else if ($trusted_author) {
        $cmd = "( cd $escrawdata && git checkout $escmain && git add $escpage $rmcmd && git commit -F $escmsgfile --author=$escauthor && git push ) 2>&1";
    } else {
        $cmd = "( cd $escrawdata && git checkout -b $escbranch && git add $escpage $rmcmd && git commit -F $escmsgfile --author=$escauthor && git push --set-upstream origin $escbranch && git checkout $escmain) 2>&1";
    }

    unset($output);
    $failed = (exec($cmd, $output, $result) === false) || ($result != 0);
    unlink($git_commit_message_file);

    if (!$failed && $trusted_author) {
        $hash = `cd $escrawdata ; git show-ref -s HEAD`;
    }

    exec("cd $escrawdata && git checkout $escmain && ( git reset --hard HEAD ; git clean -df )");   // just in case.

    $cooked = '';
    if ($trusted_author && ($existing_branch == NULL)) {
        $cooked = cook_page($page);  // this is going to recook in a moment when GitHub sends a push notification, but let's keep the state sane.
    } else {
        $cooked = cook_string($newtext, $supported_formats[$ext]);
    }

    release_git_repo_lock();

    if ($failed) {
        $str = "\n<br/>Output:<br/>\n<pre>\n";
        foreach ($output as $l) {
            $str .= "$l\n";
        }
        $str .= "</pre>\n";
        fail503("Failed to push your changes. Please try again later.$str");
    }

    if ($existing_branch != NULL) {
        print_template('added_to_pull_request', [ 'branch' => $branch, 'cooked' => fixup_preview_links($page, $cooked) ]);
    } else if ($trusted_author) {
        print_template('pushed_to_main', [ 'hash' => $hash, 'commiturl' => "$github_url/commit/$hash", 'cooked' => fixup_preview_links($page, $cooked) ]);
    } else {  // generate a pull request so we can review before applying.
        $user = $_SESSION['github_user'];
        $body = "This edit was made by @{$user}.\n\n" .
                "Live page is here: $base_url/$page\n\n" .
                "If this user should be blocked from further edits, an admin should go to $base_url/$user/block\n" .
                "If this user should be trusted to make direct pushes to $github_repo_main_branch, without a pull request, an admin should go to $base_url/$user/trust\n\n" .
                "WHETHER YOU MERGE OR REJECT THIS PULL REQUEST, DON'T FORGET TO DELETE THE BRANCH. Otherwise, $user won't be able to start a new PR for this page.\n";
        $response = call_github_api("https://api.github.com/repos/$github_repo_owner/$github_repo/pulls",
                                    [ 'head' => $branch,
                                      'base' => $github_repo_main_branch,
                                      'title' => "$comment",
                                      'body' => $body,
                                      'maintainer_can_modify' => true,
                                      'draft' => false ],
                                    $github_committer_token, true);
        print_template('made_pull_request', [ 'branch' => $branch, 'prurl' => $response['html_url'], 'cooked' => fixup_preview_links($page, $cooked) ]);
    }
}

// !!! FIXME: a lot of copy/paste from make_new_page_version
function delete_page($page, $comment)
{
    global $cooked_data, $raw_data, $git_commit_message_file, $git_committer_user, $github_url;
    global $github_committer_token, $github_repo_owner, $github_repo, $supported_formats, $base_url;
    global $github_repo_main_branch;

    force_authorize_with_github();  // only returns if we are authorized.

    $comment = str_replace("\r\n", "\n", $comment);

    if ($comment == '') {
        $comment = 'Deleted.';
    }

    $comment = "$page: $comment";

    $escrawdata = escapeshellarg($raw_data);

    obtain_git_repo_lock();

    if (file_put_contents($git_commit_message_file, $comment) != strlen($comment)) {
        unlink($git_commit_message_file);  // just in case.
        fail503('Failed to write new content to disk. Please try again later.');
    }

    $now = time();
    $branch = "delete-" . $_SESSION['github_user'] . '-' . $now;
    $author = $_SESSION['github_name'] . ' <' . $_SESSION['github_email'] . '>';
    $escbranch = escapeshellarg($branch);
    $escauthor = escapeshellarg($author);
    $escmsgfile = escapeshellarg($git_commit_message_file);

    $rmcmd = '';
    foreach ($supported_formats as $findext => $format) {
        $rmpage = "$page.$findext";
        if (file_exists("$raw_data/$rmpage")) {
            $escrmpage = escapeshellarg($rmpage);
            $rmcmd .= " $escrmpage";
        }
    }

    if ($rmcmd == '') {
        fail400("No such page to delete.");
    }

    $hash = '';
    $cmd = '';

    $trusted_author = $_SESSION['is_trusted'] || $_SESSION['is_admin'];   // trusted/admin authors push right to main. Untrusted authors generate pull requests.
    $escmain = $github_repo_main_branch;
    if ($trusted_author) {
        $cmd = "( cd $escrawdata && git checkout $escmain && git rm $rmcmd && git commit -F $escmsgfile --author=$escauthor && git push ) 2>&1";
    } else {
        $cmd = "( cd $escrawdata && git checkout -b $escbranch && git rm $rmcmd && git commit -F $escmsgfile --author=$escauthor && git push --set-upstream origin $escbranch && git checkout $escmain && git branch -d $escbranch ) 2>&1";
    }

    unset($output);
    $failed = (exec($cmd, $output, $result) === false) || ($result != 0);
    unlink($git_commit_message_file);

    if (!$failed && $trusted_author) {
        $hash = `cd $escrawdata ; git show-ref -s HEAD`;
    }

    exec("cd $escrawdata && git checkout $escmain && ( git reset --hard HEAD ; git clean -df )");   // just in case.

    release_git_repo_lock();

    if ($failed) {
        $str = "\n<br/>Output:<br/>\n<pre>\n";
        foreach ($output as $l) {
            $str .= "$l\n";
        }
        $str .= "</pre>\n";
        fail503("Failed to push your changes. Please try again later.$str");
    }

    $cooked = '[page deleted]';

    if ($trusted_author) {
        print_template('pushed_to_main', [ 'hash' => $hash, 'commiturl' => "$github_url/commit/$hash", 'cooked' => $cooked ]);
    } else {  // generate a pull request so we can review before applying.
        $user = $_SESSION['github_user'];
        $body = "This edit was made by @{$user}.\n\n" .
                "Live page is here: $base_url/$page\n\n" .
                "If this user should be blocked from further edits, an admin should go to $base_url/$user/block\n" .
                "If this user should be trusted to make direct pushes to $github_repo_main_branch, without a pull request, an admin should go to $base_url/$user/trust\n\n" .
                "WHETHER YOU MERGE OR REJECT THIS PULL REQUEST, DON'T FORGET TO DELETE THE BRANCH. Otherwise, $user won't be able to start a new PR for this page.\n";
        $response = call_github_api("https://api.github.com/repos/$github_repo_owner/$github_repo/pulls",
                                    [ 'head' => $branch,
                                      'base' => $github_repo_main_branch,
                                      'title' => "$comment",
                                      'body' => $body,
                                      'maintainer_can_modify' => true,
                                      'draft' => false ],
                                    $github_committer_token, true);
        print_template('made_pull_request', [ 'branch' => $branch, 'prurl' => $response['html_url'], 'cooked' => $cooked ]);
    }
}

function must_be_admin()
{
    force_authorize_with_github();  // only returns if we are authorized.
    if (!$_SESSION['is_admin']) {
        fail400('This is only available to admins.');
    }
}

function perform_action_on_user($action, $statedir, $user, $is_do)  // !$is_do == undo.
{
    global $github_committer_token, $wikiname;

    must_be_admin();

    $response = call_github_api("https://api.github.com/users/$user", NULL, $github_committer_token);

    // we block by user id number, not username, so the user can't evade blocking by renaming the account.
    $id = isset($response['id']) ? $response['id'] : '';
    if ($id == '') {
        fail503('Bogus user id from GitHub API. Try again later.');
    }

    $fname = "$statedir/$id";
    if ($is_do) {
        $str = "$user, {$action}ed by " . $_SESSION['github_user'] . "\n";
        @mkdir($statedir);  // don't care if this fails, it's probably EEXIST, and we'll catch the file_put_contents failure anyhow.
        if (file_put_contents($fname, $str) != strlen($str)) {
            fail503("Failed to mark user as {$action}ed. Please try again later.");
        }
    } else {
        if (file_exists($fname) && !unlink($fname)) {
            fail503("Failed to mark user as un{$action}ed. Please try again later.");
        }
    }

    print_template('action_on_user_complete', [
        'action' => $is_do ? "{$action}ed" : "un{$action}ed",
        'revaction' => $action,
        'user' => $user,
        'username' => $response['name'],
        'user_avatar' => $response['avatar_url'],
        'title' => "@$user {$action}ed - $wikiname"
    ]);
}

function confirm_action_on_user($action, $user, $statedir, $explanation)
{
    global $wikiname;

    must_be_admin();  // only returns if we are an admin.
    $response = call_github_api("https://api.github.com/users/$user", NULL, $github_committer_token);
    $is_do = file_exists("$statedir/" . $response['id']);
    print_template('action_on_user_confirmation', [
        'currently' => $is_do ? "{$action}ed" : "un{$action}ed",
        'action' => $is_do ? "un{$action}" : $action,
        'user' => $user, 'username' => $response['name'],
        'user_avatar' => $response['avatar_url'],
        'explanation' => $explanation,
        'title' => "$action @$user?- $wikiname"
    ]);
}

// this does not hold the git repo lock (but YOU SHOULD) and does not
// sort the output (BUT YOU SHOULD).
function build_index($base, $dname, &$output)
{
    global $supported_formats;

    //print("build_index base='$base' dname='$dname'\n");

    $dirp = opendir($dname);
    if ($dirp === false) {
        fail503('Failed to read directory index; please try again later.');
    }

    $sep = ($base == NULL) ? '' : '/';

    while (($dent = readdir($dirp)) !== false) {
        //print("dent='$dent'\n");
        if (substr($dent, 0, 1) == '.') {
            continue;  // skip ".", "..", and metadata.
        } else if (is_dir("$dname/$dent")) {
            build_index("$base$sep$dent", "$dname/$dent", $output);
            continue;
        } else if (preg_match('/^(.*)\.(.*)$/', $dent, $matches) != 1) {
            continue;
        }
        $pagename = $matches[1];
        $ext = $matches[2];
        if (!isset($supported_formats[$ext])) { continue; }
        //print("Adding page '$base$sep$pagename'\n");
        $output[] = "$base$sep$pagename";
    }

    closedir($dirp);

    //print("done with base='$base'\n");

    return $output;
}


$search_query = '';
function sort_search_results($a, $b)
{
    $retval = strcasecmp($a, $b);
    if ($retval != 0) {
        $bias = 0;

        global $search_query;
        $abase = (strcasecmp(basename($a), $search_query) == 0);
        $bbase = (strcasecmp(basename($b), $search_query) == 0);
        if ($abase && $bbase) {
            $retval = ($bias != 0) ? $bias : $retval;
        } else if ($abase) {
            $retval = -1;  // exact page name match? Move it to the front.
        } else if ($bbase) {
            $retval = 1;  // exact page name match? Move it to the front.
        } else if ($bias != 0) {
            $retval = $bias;
        }
    }

    return $retval;
}


// Main line!

// can't have a '/' at the end of the URL or we'll generate incorrect links.
$stripped_url = preg_replace('/\/+$/', '', $_SERVER['PHP_SELF'], -1, $count);
if ($count && ($stripped_url != '')) {
    redirect($stripped_url);
}
unset($count);

$reqargs = explode('/', preg_replace('/^\/?(.*?)\/?$/', '$1', $_SERVER['PHP_SELF']));
$reqargcount = count($reqargs);

if ( ($reqargcount < 1) || ($reqargs[0] == '') ) {
    $document = 'FrontPage';  // Feed the main wiki page by default.
} else {
    // eat arguments until we run out of existing subdirectories...
    $document = '';
    $sep = '';
    $drop_args = 0;
    foreach ($reqargs as $a) {
        $document .= "$sep$a";
        $sep = '/';  # set this after first item so there's a separator everywhere but at the start of the string.
        if (substr($a, 0, 1) == '.') {  // Make sure we don't catch ".git" or ".." or whatever.
            fail400("Invalid page name '$document'");
        }

        // Note that this (intentionally!) will not let you create files in subdirectories that don't exist.
        //  Creating a new subdir should be a significant admin-controlled event. Push through git directly for now.
        //  We can add UI for it later if we want.
        if (is_dir("$raw_data/$document")) {
            $drop_args++;
        } else {
            break;  // We're done looking for subdirs.
        }
    }

    // drop subdirs from $reqargs, replace $reqargs[0] with the full document path just in case.
    if ($drop_args > 0) {
        $reqargcount -= $drop_args;
        if ($reqargcount == 0) {
            // each subdir gets a default FrontPage. Redirect to it so there's
            // no confusion about relative URLs on the page going to the right subdir.
            redirect("$stripped_url/FrontPage");
        } else {
            while ($drop_args > 0) {
                array_shift($reqargs);  // drop subdirs from $reqargs
                $drop_args--;
            }
            $reqargs[0] = $document;
        }
    }
}

$operation = ($reqargcount >= 2) ? $reqargs[1] : 'view';

if ($operation == 'view') {  // just serve the existing page.
    // !!! FIXME: GitHub doesn't redirect properly if you click "Cancel" on their OAuth page.  :/
    handle_oauth_error();

    $published_path = "$cooked_data/$document.html";

    $preamble = '';  // unused, but you can hack something into this source code if you need it!
    $cooked = '';

    if (!file_exists($published_path)) {
        print_template('not_yet_a_page');
    } else {
        print_template('view', [ 'cooked' => file_get_contents($published_path), 'preamble' => $preamble ]);
    }

} else if ($operation == 'raw') {  // just serve the existing raw page.
    foreach ($supported_formats as $ext => $format) {
        $raw = @file_get_contents("$raw_data/$document.$ext");
        if ($raw !== false) {
            header('Content-Type: text/plain; charset=utf-8');
            print($raw);
            print("\n");
            exit(0);
        }
    }

    print_template('not_yet_a_page');

} else if ($operation == 'edit') {
    authorize_with_github();  // only returns if we are authorized.

    $template_vars = array( 'title' => "Edit $document - $wikiname" );

    obtain_git_repo_lock();

    $existing_branch = find_existing_pull_request($document, $_SESSION['github_id']);

    $content_choice = NULL;
    if (($existing_branch != NULL) && (isset($_POST['use_content_choice']))) {
        $content_choice = $_POST['use_content_choice'];
        if (($content_choice != 'use_current_page') && ($content_choice != 'use_last_changes')) {
            $content_choice = NULL;
        }
    }

    $template_vars['existing_branch'] = ($existing_branch != NULL) ? $existing_branch : '';

    if (($existing_branch != NULL) && ($content_choice == NULL)) {
        release_git_repo_lock();
        print_template('edit_existing_pull_request', $template_vars);
    } else if (($existing_branch != NULL) && ($content_choice == 'use_last_changes')) {
        $escbranch = escapeshellarg($existing_branch);
        $escdocument = escapeshellarg($document);
        $escrawdata = escapeshellarg($raw_data);

        $raw = false;
        $from_format = false;
        foreach ($supported_formats as $ext => $format) {
            $selectedstr = 'fmt_' . $ext . '_selected';
            $template_vars[$selectedstr] = '';
            if ($raw === false) {
                $cmd = "cd $escrawdata && git cat-file -p $escbranch:$escdocument.$ext 2>/dev/null";
                unset($output);
                $failed = (exec($cmd, $output, $result) === false);
                if ($failed) {
                    fail503('Failed to retrieve latest changes; please try again later.');
                } else if ($result == 0) {
                    $raw = implode("\n", $output);
                    $from_format = $format;
                    $template_vars[$selectedstr] = 'selected';
                }
            }
        }

        release_git_repo_lock();

        $template_vars['cooked'] = ($raw === false) ? '' : fixup_preview_links($document, cook_string($raw, $from_format));
        $template_vars['raw'] = ($raw === false) ? '' : $raw;
        print_template('edit', $template_vars);

    } else {  // no existing branch, or $content_choice == 'use_current_page'
        $cooked = @file_get_contents("$cooked_data/$document.html");

        $raw = false;
        foreach ($supported_formats as $ext => $format) {
            $selectedstr = 'fmt_' . $ext . '_selected';
            $template_vars[$selectedstr] = '';
            if ($raw === false) {
                $raw = @file_get_contents("$raw_data/$document.$ext");
                if ($raw !== false) {
                    $template_vars[$selectedstr] = 'selected';
                }
            }
        }

        release_git_repo_lock();

        $template_vars['cooked'] = ($cooked === false) ? '' : fixup_preview_links($document, $cooked);
        $template_vars['raw'] = ($raw === false) ? '' : $raw;
        print_template('edit', $template_vars);
    }

} else if ($operation == 'post') {
    // don't lose the changes if GitHub forces a redirect.
    require_session();

    if (isset($_POST['newversion'])) {
        $_SESSION['post_newversion'] = $_POST['newversion'];
    }

    if (isset($_POST['comment'])) {
        $_SESSION['post_comment'] = $_POST['comment'];
    }

    if (isset($_POST['format'])) {
        $_SESSION['post_format'] = $_POST['format'];
    }

    if (!isset($_SESSION['post_newversion'])) {
        fail400("New version of page was not posted with this request. <a href='/$document/edit'>Try editing again?</a>");
    }

    $data = $_SESSION['post_newversion'];
    $comment = isset($_SESSION['post_comment']) ? $_SESSION['post_comment'] : '';
    $format = isset($_SESSION['post_format']) ? $_SESSION['post_format'] : 'md';
    make_new_page_version($document, $format, $data, $comment);

    unset($_SESSION['post_newversion']);
    unset($_SESSION['post_comment']);
    unset($_SESSION['post_format']);

} else if ($operation == 'delete') {
    authorize_with_github();  // only returns if we are authorized.
    print_template('delete_confirmation', [ 'title' => "Delete $document? - $wikiname" ]);

} else if ($operation == 'postdelete') {
    // don't lose the changes if GitHub forces a redirect.
    require_session();

    if (isset($_POST['comment'])) {
        $_SESSION['post_comment'] = $_POST['comment'];
    }

    $comment = isset($_SESSION['post_comment']) ? $_SESSION['post_comment'] : '';
    delete_page($document, $comment);

    unset($_SESSION['post_comment']);

} else if ($operation == 'format') {
    // ask the server to cook a string of Markdown/MediaWiki/whatever on the fly for live previews.

    // see if user has an access token (but don't validate it) to prevent server load; drive-by
    //  bots can't use this unless they have auth'd with github at some point before, like when
    //  they clicked the 'edit' link right before they would need this.
    require_session();
    if (!isset($_SESSION['github_access_token'])) {
        fail400("live previews only available when logged into GitHub.");
    }

    $data = isset($_POST['raw']) ? $_POST['raw'] : '';
    $format = isset($_POST['format']) ? $_POST['format'] : 'md';
    $pandoc_format = isset($supported_formats[$format]) ? $supported_formats[$format] : NULL;

    if ($pandoc_format == NULL) {
        fail400('Unsupported document format');
    }

    if ($data != '') {
        $data = str_replace("\r\n", "\n", $data);
        $data = cook_string($data, $pandoc_format);
        $data = fixup_preview_links($document, $data);
        print($data);
    }
} else if ($operation == 'history') {
    foreach ($supported_formats as $ext => $format) {
        if (file_exists("$raw_data/$document.$ext")) {
            redirect("$github_url/commits/$github_repo_main_branch/$document.$ext");
        }
    }
    fail404("No such page '$document'");

} else if ($operation == 'block') {
    confirm_action_on_user($operation, $document, $blocked_data, 'Blocked users may not make edits to the wiki, but may still read it.');

} else if (($operation == 'block_confirm') || ($operation == 'unblock_confirm')) {
    perform_action_on_user('block', $blocked_data, $document, ($operation == 'block_confirm'));

} else if ($operation == 'trust') {
    confirm_action_on_user($operation, $document, $trusted_data, "Trusted users' edits are pushed directly to <b>$github_repo_main_branch</b> without generating pull requests.");

} else if (($operation == 'trust_confirm') || ($operation == 'untrust_confirm')) {
    perform_action_on_user('trust', $trusted_data, $document, ($operation == 'trust_confirm'));

} else if ($operation == 'admin') {
    confirm_action_on_user($operation, $document, $admin_data, "Admins can change wiki and user settings, and push changes directly to <b>$github_repo_main_branch</b> without generating pull requests.");

} else if (($operation == 'admin_confirm') || ($operation == 'unadmin_confirm')) {
    perform_action_on_user('admin', $admin_data, $document, ($operation == 'admin_confirm'));

} else if ($operation == 'index') {
    $pagelist = array();
    build_index(NULL, $raw_data, $pagelist);
    $htmllist = '';
    asort($pagelist, SORT_STRING|SORT_FLAG_CASE);
    foreach ($pagelist as $p) {
        $htmllist .= "<li><a href='/$p'>$p</a></li>\n";
    }

    print_template('index', [ 'title' => "Index of all pages - $wikiname", 'htmllist' => $htmllist ]);

} else if ($operation == 'search') {
    // !!! FIXME: move this to a separate function.
    // !!! FIXME: maybe limit searches to people auth'd with GitHub to prevent server load from crawlers?
    $search_query = isset($_REQUEST['q']) ? $_REQUEST['q'] : '';  // let this be a GET option so people can post search URLs.
    $htmllist = '';
    $htmlquery = '';
    $queryurl = '';
    $hideifblank = 'none';
    if ($search_query != '') {
        $hideifblank = 'inline';
        $htmlquery = htmlspecialchars($search_query, ENT_QUOTES|ENT_SUBSTITUTE|ENT_DISALLOWED|ENT_HTML5, 'UTF-8');
        $queryurl = rawurlencode($search_query);
        $escquery = escapeshellarg($search_query);
        $cmd = "csearch -i $escquery";
        unset($output);
        $failed = (exec($cmd, $output, $result) === false);
        if ($failed) {
            fail503('Failed to run search query; please try again later.');
        }

        $pagehits = array();
        foreach ($output as $l) {
            if (preg_match("/^.*\/$raw_data\/(.*)\..*?\:(.*)$/", $l, $matches) != 1) { continue; }
            $p = $matches[1];
            $txt = $matches[2];

            // skip the title on the actual page
            if (preg_match("/^# $search_query$/", $txt, $matches) == 1) { continue; }
            if (preg_match("/^= $search_query =$/", $txt, $matches) == 1) { continue; }

            // skip the redirect pages (these are only in Markdown format).
            if (preg_match("/^Please refer to \[.*?\]\(.*?\) for details.$/", $txt, $matches) == 1) { continue; }

            // skip what are probably See Also links.
            if ((preg_match("/^\- \[(.*?)\]\((.*?)\)$/", $txt, $matches) == 1) && ($matches[1] == $matches[2])) { continue; }
            if ((preg_match("/^\* \[\[(.*?)\]\]$/", $txt, $matches) == 1)) { continue; }

            if (!isset($pagehits[$p])) {
                $pagehits[$p] = array();
            }
            $pagehits[$p][] = $txt;
        }

        if (count($pagehits) == 0) {
            $htmllist .= "<li>No results found.</li>\n";
        } else {
            uksort($pagehits, "sort_search_results");

            foreach ($pagehits as $p => $txt) {
                $htmllist .= "<li><a href='/$p'>$p</a>:<dl>\n";
                $first = true;
                foreach ($txt as $t) {
                    $htmltxt = $t; //htmlspecialchars($t, ENT_QUOTES|ENT_SUBSTITUTE|ENT_DISALLOWED|ENT_HTML5, 'UTF-8');
                    if ($first) {
                        $first = false;
                    } else {
                        $htmllist .= "\n<dt/><dd>...</dd></dl>\n";
                    }
                    $htmllist .= "<dt/><dd><pre>$htmltxt</pre></dd>";
                }
                $htmllist .= "\n</li>\n";
            }
        }
    }
    print_template('search', [ 'title' => "Search - $wikiname", 'query' => $htmlquery, 'queryurl' => $queryurl, 'htmllist' => $htmllist, 'hideifblank' => $hideifblank ]);

} else if ($operation == 'logout') {
    require_session();
    $_SESSION = array();  // nuke everything.
    redirect("/$document", 'You have been logged out.');

} else if ($operation == 'webhook') {
    github_webhook();    // GitHub POSTs here whenever the wiki repo is pushed to.

} else if ($operation == 'recook') {  // !!! FIXME: this should be a recookall, and "recook" should just redo $document.
    must_be_admin();

    // !!! FIXME: code duplication with the GitHub webhook.

    header('Content-Type: text/plain; charset=utf-8');
    $str = "\nRECOOKING...\n\n";

    $starttime = microtime(true);
    $updated = array();
    $failed = false; // !!! FIXME: recook_tree returns void atm
    recook_tree($raw_data, $cooked_data, '', $updated);
    foreach ($updated as $f) {
        $str .= "$f\n";
    }
    $str .= "\n";

    if ($failed) {
        fail503("$str\nFAILED TO RECOOK DATA!\n", 'fail_plaintext');
    }

    recook_search_index();

    $totaltime = (int) (microtime(true) - $starttime);
    $str .= "\nTREE RECOOKED IN $totaltime SECONDS.\n";
    print("$str\n");

} else {
    fail400("Unknown operation '$operation'");
}

exit(0);
?>

