<?php

require_once('config.php');

$supported_formats = [
    'md' => 'markdown_github+backtick_code_blocks',
    'mediawiki' => 'mediawiki'
];

$github_url = "https://github.com/$github_repo_owner/$github_repo";
$document = NULL;
$operation = NULL;

putenv("GIT_SSH_COMMAND=ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i $ssh_key_fname");
putenv("GIT_COMMITTER_NAME=$git_committer_name");
putenv("GIT_COMMITTER_EMAIL=$git_committer_email");


$git_repo_lock_fp = false;
$git_repo_lock_count = 0;
function obtain_git_repo_lock()
{
    global $repo_lock_fname, $git_repo_lock_fp, $git_repo_lock_count;
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
    global $document, $operation;

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
               function ($matches) {
                   return get_template($matches[1], $vars, $safe_to_fail);
               },
               $str
           );

    // replace any @varname@ with the value of that variable.
    return preg_replace_callback(
               '/\@(.*?)\@/',
               function ($matches) use ($vars, $document, $operation) {
                   $key = $matches[1];
                   if (($vars != NULL) && isset($vars[$key])) { return $vars[$key]; }
                   else if ($key == 'page') { return $document; }
                   else if ($key == 'operation') { return $operation; }
                   else if ($key == 'url') { return $_SERVER['PHP_SELF']; }
                   else if ($key == 'github_user') { return isset($_SESSION['github_user']) ? $_SESSION['github_user'] : '[not logged in]'; }
                   else if ($key == 'github_name') { return isset($_SESSION['github_name']) ? $_SESSION['github_name'] : '[not logged in]'; }
                   else if ($key == 'github_email') { return isset($_SESSION['github_email']) ? $_SESSION['github_email'] : '[not logged in]'; }
                   else if ($key == 'github_avatar') { return isset($_SESSION['github_avatar']) ? $_SESSION['github_avatar'] : ''; }  // !!! FIXME: return a generic image instead of ''
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

    $proc = proc_open([ 'pandoc', '-f', $from_pandoc_format, '-t', 'html', '--toc' ], $descriptorspec, $pipes);

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
            file_put_contents($cookedfname, $cooked);
        }
    }
    release_git_repo_lock();

    return $cooked;
}

function cook_page($page)
{
    global $raw_data, $cooked_data;
    return cook_page_from_files("$raw_data/$page.md", "$cooked_data/$page.html");
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
            $dst = preg_replace('/\..*?$/', '.html', $dst);
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

        if (!found) {
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
    global $raw_data, $cooked_data, $trusted_data, $trust_threshold;

    header('Content-Type: text/plain; charset=utf-8');

    $str = '';

    $signature = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : NULL;
    $event = isset($_SERVER['HTTP_X_GITHUB_EVENT']) ? $_SERVER['HTTP_X_GITHUB_EVENT'] : NULL;
    $delivery = isset($_SERVER['HTTP_X_GITHUB_DELIVERY']) ? $_SERVER['HTTP_X_GITHUB_DELIVERY'] : NULL;

    if (!isset($signature, $event, $delivery)) {
        fail400("$str\nBAD SIGNATURE\n", 'fail_plaintext');
    }

    $payload = file_get_contents('php://input');

    // Check if the payload is json or urlencoded.
    if (strpos($payload, 'payload=') === 0) {
        $payload = substr(urldecode($payload), 8);
    }

    if (!validate_webhook_signature($signature, $payload)) {
        fail400("$str\nBAD SIGNATURE\n", 'fail_plaintext');
    }

    $str .= "SIGNATURE OK\n";
    $str .= "GITHUB EVENT: $event\n";

    if ($event == 'push') {   // we only care about push events.
        obtain_git_repo_lock();
        $escrawdata = escapeshellarg($raw_data);
        $cmd = "( cd $escrawdata && git pull --rebase ) 2>&1";
        unset($output);
        $failed = (exec($cmd, $output, $result) === false) || ($result != 0);

        $str .= "\nOUTPUT of '$cmd':\n\n";
        foreach ($output as $l) {
            $str .= "    $l\n";
        }

        if ($failed) {
            fail503("$str\nGIT PULL FAILED!\n", 'fail_plaintext');
        }

        $updated = array();
        $failed = recook_tree($raw_data, $cooked_data, '', $updated);

        $str .= "\n";
        foreach ($updated as $f) {
            $str .= "$f\n";
        }
        $str .= "\n";

        if ($failed) {
            fail503("$str\nFAILED TO RECOOK DATA!\n", 'fail_plaintext');
        }

        $str .= "\nTREE RECOOKED.\n";

        $cmd = "cd $escrawdata && git log --format='%ae' main";
        unset($output);
        $failed = (exec($cmd, $output, $result) === false) || ($result != 0);
        if ($failed) {
            $str .= "FAILED TO GET AUTHOR LIST, NOT UPDATING TRUSTED USERS\n";
        } else {
            $users = array();
            foreach ($output as $l) {
                if (!isset($users[$l])) {
                    $users[$l] = 1;
                } else {
                    $users[$l]++;
                }
            }

            $str .= "Author commit counts:\n";
            foreach ($users as $u => $num) {
                $trusted = '';
                if ($num >= $trust_threshold) {
                    $trusted = ' [TRUSTED]';
                    $f = "$trusted_data/$u";
                    if (!file_exists($f)) {
                        file_put_contents($f, '');
                    }
                }
                $str .= "    $u: $num$trusted\n";
            }
            $str .= "\n";
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


function authorize_with_github($force=false)
{
    global $github_oauth_clientid;
    global $github_oauth_secret;

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
         isset($_SESSION['github_user']) &&
         isset($_SESSION['github_email']) &&
         isset($_SESSION['github_name']) &&
         isset($_SESSION['github_access_token']) &&
         isset($_SESSION['last_auth_time']) &&
         isset($_SESSION['expected_ipaddr']) &&
         ( (time() - $_SESSION['last_auth_time']) < (60 * 60) ) &&
         ($_SESSION['expected_ipaddr'] == $_SERVER['REMOTE_ADDR']) ) {
        //print("ALREADY LOGGED IN\n");
        return;  // we're already logged in.

    } else if (isset($_REQUEST['code'])) {  // this is probably a redirect back from GitHub's OAuth page.
        if ( !isset($_REQUEST['state']) || !isset($_SESSION['github_oauth_state']) ) {
            fail400('GitHub authorization appears to be confused, please try again.');
        } else if ($_SESSION['github_oauth_state'] != $_REQUEST['state']) {
            fail400('This appears to be a bogus attempt to authorize with GitHub. If in error, please try again.');
        }

        $tokenurl = 'https://github.com/login/oauth/access_token';
        $response = call_github_api($tokenurl, [
            'client_id' => $github_oauth_clientid,
            'client_secret' => $github_oauth_secret,
            'state' => $_REQUEST['state'],
            'code' => $_REQUEST['code']
        ]);

        //print_r($response);

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
            //print("GITHUB USER API RESPONSE:\n"); print_r($response);

            if ( !isset($response['login']) ||
                 !isset($response['name']) ||
                 !isset($response['email']) ) {
                unset($_SESSION['github_access_token']);
                fail503("GitHub didn't tell us everything we need to know about you. Please try again later.");
            }

            $_SESSION['expected_ipaddr'] = $_SERVER['REMOTE_ADDR'];
            $_SESSION['last_auth_time'] = time();
            $_SESSION['github_user'] = $response['login'];
            $_SESSION['github_email'] = $response['email'];
            $_SESSION['github_name'] = $response['name'];
            $_SESSION['github_avatar'] = isset($response['avatar_url']) ? $response['avatar_url'] : '';

            //print("SESSION:\n"); print_r($_SESSION);

            // !!! FIXME: see if we're blocked, and drop session immediately and fail.

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
    unset($_SESSION['github_user']);
    unset($_SESSION['github_email']);
    unset($_SESSION['github_name']);
    unset($_SESSION['github_avatar']);
    unset($_SESSION['github_access_token']);
    unset($_SESSION['expected_ipaddr']);
    unset($_SESSION['last_auth_time']);

    // !!! FIXME: no idea if this is a good idea.
    $_SESSION['github_oauth_state'] = hash('sha256', microtime(TRUE) . rand() . $_SERVER['REMOTE_ADDR']);

    $github_authorize_url = 'https://github.com/login/oauth/authorize';
    redirect($github_authorize_url . '?' . http_build_query([
        'client_id' => $github_oauth_clientid,
        'redirect_uri' => 'https://testwiki.libsdl.org' . $_SERVER['PHP_SELF'],
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

function make_new_page_version($page, $ext, $newtext, $comment, $trusted_author)
{
    global $raw_data, $git_commit_message_file, $git_committer_user, $github_url;
    global $github_committer_token, $github_repo_owner, $github_repo, $supported_formats;

    if (!isset($_SESSION['github_access_token'])) {  // should have called authorize_with_github before this!
        fail503('BUG: This program should have had you authorize with GitHub first!');
    }

    $newtext = str_replace("\r\n", "\n", $newtext);
    $comment = str_replace("\r\n", "\n", $comment);

    if ($comment == '') {
        $comment = 'Updated.';
    }

    $gitfname = "$raw_data/$page.$ext";
    $escpage = escapeshellarg("$page.$ext");
    $escrawdata = escapeshellarg($raw_data);

    obtain_git_repo_lock();

    if (file_put_contents($git_commit_message_file, $comment) != strlen($comment)) {
        unlink($git_commit_message_file);  // just in case.
        fail503('Failed to write new content to disk. Please try again later.');
    }

    @mkdir(dirname($gitfname));
    if (file_put_contents($gitfname, $newtext) != strlen($newtext)) {
        exec("cd $escrawdata && git checkout -- $escpage");
        unlink($git_commit_message_file);
        fail503('Failed to write new content to disk. Please try again later.');
    }

    $now = time();
    $branch = "edit-" . $_SESSION['github_user'] . '-' . $now;
    $author = $_SESSION['github_name'] . ' <' . $_SESSION['github_email'] . '>';
    $escbranch = escapeshellarg($branch);
    $escauthor = escapeshellarg($author);
    $escmsgfile = escapeshellarg($git_commit_message_file);

    // remove files if the file extension changed.
    $rmcmd = '';
    foreach ($supported_formats as $findext => $format) {
        if ($ext != $findext) {
            $rmpage = "$raw_data/$page.$findext";
            if (file_exists($rmpage)) {
                $escrmpage = escapeshellarg($rmpage);
                $rmcmd .= " && git rm $escrmpage";
            }
        }
    }

    $cmd = '';
    if ($trusted_author) {   // trusted authors push right to main. Untrusted authors generate pull requests.
        $cmd = "( cd $escrawdata && git checkout main && git add $escpage $rmcmd && git commit -F $escmsgfile --author=$escauthor && git push ) 2>&1";
    } else {
        $cmd = "( cd $escrawdata && git checkout -b $escbranch && git add $escpage $rmcmd && git commit -F $escmsgfile --author=$escauthor && git push --set-upstream origin $escbranch ) 2>&1";
    }

    unset($output);
    $failed = (exec($cmd, $output, $result) === false) || ($result != 0);
    unlink($git_commit_message_file);
    exec("cd $escrawdata && git checkout main && ( git reset --hard HEAD ; git clean -df )");   // just in case.

    $cooked = '';
    if ($trusted_author) {
        $cooked = cook_page($page);  // this is going to recook in a moment when GitHub sends a push notification, but let's keep the state sane.
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

    if ($trusted_author) {
        $hash = `cd $escrawdata ; git show-ref -s HEAD`;
        print_template('pushed_to_main', [ 'hash' => $hash, 'commiturl' => "$github_url/commit/$hash", 'cooked' => $cooked ]);
    } else {  // generate a pull request so we can review before applying.
        $response = call_github_api("https://api.github.com/repos/$github_repo_owner/$github_repo/pulls",
                                    [ 'head' => $branch,
                                      'base' => 'main',
                                      'title' => "$page: $comment ($now)",
                                      'maintainer_can_modify' => true,
                                      'draft' => false ],
                                    $github_committer_token, true);
        print_template('made_pull_request', [ 'branch' => $branch, 'prurl' => $response['html_url'] ]);
    }
}

function is_trusted_author($author)
{
    global $trusted_data;
    return file_exists("$trusted_data/$author");
}


// Main line!

$reqargs = explode('/', preg_replace('/^\/?(.*?)\/?$/', '$1', $_SERVER['PHP_SELF']));
$reqargcount = count($reqargs);

if ( ($reqargcount < 1) || ($reqargs[0] == '') ) {
    $document = 'FrontPage';  // Feed the main wiki page by default.
} else {
    $document = $reqargs[0];
    if (substr($document, 0, 1) == '.') {  // Make sure we don't catch ".git" or ".." or whatever.
        fail400("Invalid page name '$document'");
    }
}

$operation = ($reqargcount >= 2) ? $reqargs[1] : 'view';

if ($operation == 'view') {  // just serve the existing page.
    // !!! FIXME: GitHub doesn't redirect properly if you click "Cancel" on their OAuth page.  :/
    handle_oauth_error();

    $published_path = "$cooked_data/$document.html";

    $cooked = '';
    obtain_git_repo_lock();
    if (!file_exists($published_path)) {
        print_template('not_yet_a_page');
    } else {
        print_template('view', [ 'cooked' => file_get_contents($published_path) ]);
    }
    release_git_repo_lock();

} else if ($operation == 'edit') {
    authorize_with_github();  // only returns if we are authorized.
    obtain_git_repo_lock();
    $cooked = @file_get_contents("$cooked_data/$document.html");

    $raw = false;
    foreach ($supported_formats as $ext => $format) {
        $raw = @file_get_contents("$raw_data/$document.$ext");
        if ($raw !== false) {
            break;
        }
    }

    release_git_repo_lock();
    print_template('edit', [ 'cooked' => ($cooked === false) ? '' : $cooked, 'raw' => ($raw === false) ? '' : $raw ]);

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

    force_authorize_with_github();  // only returns if we are authorized.

    if (!isset($_SESSION['post_newversion'])) {
        fail400("New version of page was not posted with this request. <a href='/$document/edit'>Try editing again?</a>");
    }

    $data = $_SESSION['post_newversion'];
    $comment = isset($_SESSION['post_comment']) ? $_SESSION['post_comment'] : '';
    $format = isset($_SESSION['post_format']) ? $_SESSION['post_format'] : 'md';
    $trusted = is_trusted_author($_SESSION['github_email']);
    make_new_page_version($document, $format, $data, $comment, $trusted);

    unset($_SESSION['post_newversion']);
    unset($_SESSION['post_comment']);
    unset($_SESSION['post_format']);

} else if ($operation == 'history') {
    foreach ($supported_formats as $ext => $format) {
        if (file_exists("$raw_data/$document.$ext")) {
            redirect("$github_url/commits/main/$document.$ext");
        }
    }
    fail404("No such page '$document'");

} else if ($operation == 'logout') {
    require_session();
    $_SESSION = array();  // nuke everything.
    redirect("/$document", 'You have been logged out.');

} else if ($operation == 'webhook') {
    github_webhook();    // GitHub POSTs here whenever the wiki repo is pushed to.

} else {
    fail400("Unknown operation '$operation'");
}

exit(0);
?>

