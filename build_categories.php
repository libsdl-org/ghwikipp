#!/usr/bin/php
<?php

// this is meant to be used from the command line, not a webpage, but
//  it's written in PHP so we can reuse config.php and stuff.

chdir(dirname(__FILE__));  // just in case.
require_once('config.php');

// !!! FIXME: put this in a common.php file or something.
$supported_formats = [
    'md' => 'markdown_github',
    'mediawiki' => 'mediawiki'
];

$escrawdata = escapeshellarg($raw_data);

putenv("GIT_SSH_COMMAND=ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -q -i $ssh_key_fname");
putenv("GIT_COMMITTER_NAME=$git_committer_name");
putenv("GIT_COMMITTER_EMAIL=$git_committer_email");
putenv("GIT_AUTHOR_NAME=$git_committer_name");
putenv("GIT_AUTHOR_EMAIL=$git_committer_email");


$categories = array();
function build_category_lists($srcdir)
{
    global $supported_formats, $categories;

    $dirp = opendir($srcdir);
    if ($dirp === false) {
        print("Failed to opendir '$srcdir'\n");
        exit(1);
    }

    while (($dent = readdir($dirp)) !== false) {
        if (substr($dent, 0, 1) == '.') { continue; }  // skip ".", "..", and metadata.
        $src = "$srcdir/$dent";
        if (is_dir($src)) {
            // categories are per-subdir, don't walk the tree.
            continue;  //build_category_lists($src);
        } else {
            $ext = strrchr($dent, '.');

            if ($ext === false) {
                continue;
            }

            $ext = substr($ext, 1);
            //print("cookext: '$ext'\n");

            if (!isset($supported_formats[$ext])) {
                continue;
            }

            $from_format = $supported_formats[$ext];
            $page = preg_replace('/^.*\//', '', $dent);
            $page = preg_replace('/\..*$/', '', $page);
            $fp = fopen($src, 'r');
            if (!$fp) {
                print("Failed to open '$src'!\n");
                exit(1);
            }

            while (($line = fgets($fp)) !== false) {
                // The categories come right after a '----' line.
                if (trim($line) == '----') {
                    if (($line = fgets($fp)) !== false) {
                        $cats = explode(',', trim($line));
                        foreach ($cats as $c) {
                            $c = preg_replace('/^\[\[(.*?)\]\]$/', '$1', trim($c));
                            if (!isset($categories[$c])) {
                                $categories[$c] = array();
                            }
                            //print("Adding '$page' to '$c'\n");
                            $categories[$c][$page] = true;
                        }
                    }
                }
            }
            fclose($fp);
        }
    }

    closedir($dirp);
}

function write_category_list($fp, $pages, $ismediawiki)
{
    ksort($pages, SORT_STRING|SORT_FLAG_CASE);
    fputs($fp, "<!-- BEGIN CATEGORY LIST -->\n");
    //$last_letter = '';
    foreach ($pages as $p => $unused) {
        // this isn't super-useful when everything starts with "SDL_"  :)
        /*
        $letter = strtoupper(substr($p, 0, 1));
        if ($letter != $last_letter) {
            $last_letter = $letter;
            fputs($fp, "* $letter\n");
        }
        */
        if ($ismediawiki) {
            fputs($fp, "* [[$p]]\n");
        } else {
            fputs($fp, "- [$p]($p)\n");
        }
    }
    fputs($fp, "<!-- END CATEGORY LIST -->\n");
}

function find_subdirs($base, $dname, &$output)
{
    $dirp = opendir($dname);
    if ($dirp === false) {
        return;  // oh well.
    }

    $sep = ($base == NULL) ? '' : '/';

    while (($dent = readdir($dirp)) !== false) {
        $path = "$dname/$dent";
        if (substr($dent, 0, 1) == '.') {
            continue;  // skip ".", "..", and metadata.
        } else if (is_dir($path)) {
            $thisbase = "$base$sep$dent";
            $output[] = $thisbase;
            find_subdirs($thisbase, $path, $output);
        }
    }

    closedir($dirp);

    return $output;
}

function handle_subdir($dname)
{
    global $categories;

    $categories = array();
    build_category_lists($dname);

    foreach ($categories as $cat => $pages) {
        //print("CATEGORY '$cat':\n");
        //print_r($pages);

        // keep in MediaWiki format if it exists, start new pages in Markdown.
        $ismediawiki = true;
        $path = "$dname/$cat.mediawiki";
        if (!file_exists($path)) {
            $ismediawiki = false;
            $path = "$dname/$cat.md";
            if (!file_exists($path)) {
                file_put_contents($path, "# $cat\n\n<!-- BEGIN CATEGORY LIST -->\n<!-- END CATEGORY LIST -->\n\n");
            }
        }

        $tmppath = "$path.tmp";
        $contents = '';

        $in = fopen($path, "r");
        if ($in === false) {
            print("Failed to open '$path' for reading\n");
            system("cd $escrawdata && git clean -dfq && git checkout -- .");
            exit(1);
        }

        $out = fopen($tmppath, "w");
        if ($out === false) {
            print("Failed to open '$tmppath' for writing\n");
            system("cd $escrawdata && git clean -dfq && git checkout -- .");
            exit(1);
        }

        $wrote_list = false;
        while (($line = fgets($in)) !== false) {
            //print("LINE: [" . trim($line) . "]\n");
            if (trim($line) == '----') {  // the footer? Just stuff the list before it, oh well.
                if (!$wrote_list) {
                    write_category_list($out, $pages);
                    $wrote_list = true;
                }
                fputs($out, "----\n");
            } else if (trim($line) == '<!-- BEGIN CATEGORY LIST -->') {
                if (!$wrote_list) {
                    write_category_list($out, $pages);
                    $wrote_list = true;
                }
                while (($line = fgets($in)) !== false) {
                    if (trim($line) == '<!-- END CATEGORY LIST -->') {
                        break;
                    }
                }
            } else {
                fputs($out, $line);
            }
        }

        fclose($in);

        if (!$wrote_list) {
            write_category_list($out, $pages, $ismediawiki);
        }

        fclose($out);

        if (!rename($tmppath, $path)) {
            unlink($tmppath);
            print("Failed to rename '$tmppath' to '$path'!\n");
            system("cd $escrawdata && git clean -dfq && git checkout -- .");
            exit(1);
        }
    }
}


// Mainline!

$git_repo_lock_fp = fopen($repo_lock_fname, 'c+');
if ($git_repo_lock_fp === false) {
    print("Failed to obtain Git repo lock. Please try again later.\n");
    exit(1);
} else if (flock($git_repo_lock_fp, LOCK_EX) === false) {
    print("Exclusive flock of Git repo lock failed. Please try again later.");  // uh...?
    exit(1);
}

handle_subdir($raw_data);  // get the root directory.

$subdirs = array();
find_subdirs(NULL, $raw_data, $subdirs);
foreach ($subdirs as $d) {
    handle_subdir("$raw_data/$d");
}

unset($output);
$failed = ((exec("cd $escrawdata && git status -s |wc -l", $output, $rc) === false) || ($rc != 0));
if ($failed) {
    print("Failed to run 'git status'!\n");
    system("cd $escrawdata && git clean -dfq && git checkout -- .");
    exit(1);
}

$changes = (isset($output[0]) && $output[0] != '0');
if ($changes) {
    $cmd = "( cd $escrawdata && git add -A && git commit -m 'Sync category pages' && git push ) 2>&1";
    unset($output);
    $failed = (exec($cmd, $output, $result) === false) || ($result != 0);
    if ($failed) {
        print("FAILED GIT RUN!\n\n    cmd:\n'$cmd'\n\noutput:\n");
        foreach ($output as $l) {
            print("    $l\n");
        }
        exit(1);
    }
}

flock($git_repo_lock_fp, LOCK_UN);
fclose($git_repo_lock_fp);
unset($git_repo_lock_fp);

exit(0);
?>
