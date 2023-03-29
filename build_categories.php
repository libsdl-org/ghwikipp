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

// Category pages have a main list in a <!-- BEGIN CATEGORY LIST --> section.
// Category pages can have sub-lists of pages that are also in another category.
// These pages are not included in the main list.
// e.g., CategoryEnum in a separate list in <!-- BEGIN ENUM LIST --> section.
$sublists = [
    'ENUM' => 'CategoryEnum',
    'STRUCT' => 'CategoryStruct'
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

                // Strictly speaking, it's legal in Markdown to do:
                //
                // My sub-header
                // -------------
                //
                // (which is equivalent to "# My sub-header" on a single line.)
                // ...so this can catch nonsense if the sub-header is four chars long,
                // but we attempt to mitigate.
                if (trim($line) == '----') {
                    if (($line = fgets($fp)) !== false) {
                        $cats = explode(',', trim($line));
                        foreach ($cats as $c) {
                            $c = trim($c);
                            $count = 0;
                            if ($from_format == "mediawiki") {
                                $c = preg_replace('/^\[\[(.*?)\]\]$/', '$1', $c, 1, $count);
                            } else if ($from_format == "markdown_github") {
                                $c = preg_replace('/^\[(.*?)\]\(.*?\)$/', '$1', $c, 1, $count);
                            }
                            // currently we have pages that don't have these wikilinked, so don't check $count==1 here for now.
                            if (/*($count == 1) &&*/ ($c != "")) {
                                if (!isset($categories[$c])) {
                                    $categories[$c] = array();
                                }
                                //print("Adding '$page' to '$c'\n");
                                $categories[$c][$page] = true;
                            }
                        }
                    }
                }
            }
            fclose($fp);
        }
    }

    closedir($dirp);
}

function write_category_list($fp, $listname, $pages, $ismediawiki)
{
    ksort($pages, SORT_STRING|SORT_FLAG_CASE);
    fputs($fp, "<!-- BEGIN $listname LIST -->\n");
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
    fputs($fp, "<!-- END $listname LIST -->\n");
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
    global $categories, $sublists;

    $categories = array();
    build_category_lists($dname);

    foreach ($categories as $cat => $pages) {
        //print("DIR '$dname' CATEGORY '$cat':\n");
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

        $sub_pages = array();
        $wrote_sub_list = array();
        foreach($sublists as $listname => $subcat) {
            $sub_pages[$listname] = array();
            $wrote_sub_list[$listname] = false;

            if ($cat !== $subcat && !empty($categories[$subcat])) {
                $sub_pages[$listname] = array_intersect_assoc($pages, $categories[$subcat]);
            }

            // Remove sub category pages from the main category list
            $pages = array_diff_assoc($pages, $sub_pages[$listname]);
        }

        $wrote_list = false;
        while (($line = fgets($in)) !== false) {
            //print("LINE: [" . trim($line) . "]\n");
            if (trim($line) == '----') {  // the footer? Just stuff the list before it, oh well.
                foreach($sublists as $listname => $subcat) {
                    if (!$wrote_sub_list[$listname] && !empty($sub_pages[$listname])) {
                        write_category_list($out, $listname, $sub_pages[$listname], $ismediawiki);
                        fputs($out, "\n");
                        $wrote_sub_list[$listname] = true;
                    }
                }

                if (!$wrote_list) {
                    write_category_list($out, 'CATEGORY', $pages, $ismediawiki);
                    fputs($out, "\n");
                    $wrote_list = true;
                }

                fputs($out, "----\n");
            } else if (trim($line) == '<!-- BEGIN CATEGORY LIST -->') {
                if (!$wrote_list) {
                    write_category_list($out, 'CATEGORY', $pages, $ismediawiki);
                    $wrote_list = true;
                }
                while (($line = fgets($in)) !== false) {
                    if (trim($line) == '<!-- END CATEGORY LIST -->') {
                        break;
                    }
                }
            } else {
                $trimedline = trim($line);
                $foundList = false;
                if (substr($trimedline, 0, 11) === "<!-- BEGIN ") {
                    foreach($sublists as $listname => $subcat) {
                        if ($trimedline == "<!-- BEGIN $listname LIST -->") {
                            if (!$wrote_sub_list[$listname]) {
                                write_category_list($out, $listname, $sub_pages[$listname], $ismediawiki);
                                $wrote_sub_list[$listname] = true;
                            }
                            while (($line = fgets($in)) !== false) {
                                if (trim($line) == "<!-- END $listname LIST -->") {
                                    break;
                                }
                            }

                            $foundList = true;
                            break;
                        }
                    }
                }

                if ($foundList === false) {
                    fputs($out, $line);
                }
            }
        }

        fclose($in);

        foreach($sublists as $listname => $subcat) {
            if (!$wrote_sub_list[$listname] && !empty($sub_pages[$listname])) {
                write_category_list($out, $listname, $sub_pages[$listname], $ismediawiki);
                fputs($out, "\n");
            }
        }

        if (!$wrote_list) {
            write_category_list($out, 'CATEGORY', $pages, $ismediawiki);
            fputs($out, "\n");
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
