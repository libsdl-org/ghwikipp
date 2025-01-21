#!/usr/bin/php
<?php

// this is meant to be used from the command line, not a webpage, but
//  it's written in PHP so we can reuse config.php and stuff.

chdir(dirname(__FILE__));  // just in case.
require_once('config.php');

// !!! FIXME: put this in a common.php file or something.
$supported_formats = [
    'md' => 'gfm',
    'mediawiki' => 'mediawiki'
];


$max_children = 4;
$num_children = 0;

function cook_tree_for_offline_html($srcdir, $dstdir)
{
    global $supported_formats, $max_children, $num_children;

    //print("cooktree: '$srcdir' -> '$dstdir'\n");
    $dirp = opendir($srcdir);
    if ($dirp === false) {
        return;
    }

    while (($dent = readdir($dirp)) !== false) {
        if (substr($dent, 0, 1) == '.') { continue; }  // skip ".", "..", and metadata.
        //print("cookdent: '$dent'\n");
        $src = "$srcdir/$dent";
        $dst = "$dstdir/$dent";
        if (is_dir($src)) {
            mkdir($dst);
            cook_tree_for_offline_html($src, $dst);
        } else {
            $ext = strrchr($dent, '.');
            if ($ext !== false) {
                $ext = substr($ext, 1);
                //print("cookext: '$ext'\n");
                if (isset($supported_formats[$ext])) {
                    $from_format = $supported_formats[$ext];
                    $page = preg_replace('/\..*?$/', '', $dst);
                    $page = preg_replace('/^.*\//', '', $page);
                    $dst = preg_replace('/\..*?$/', '.html', $dst);

                    // split this across CPU cores.
                    while ($num_children >= $max_children) {
                        pcntl_waitpid(-1, $status);  // wait for any child to end.
                        $num_children--;
                    }
                    // launch another!
                    $pid = pcntl_fork();
                    if ($pid == -1) {
                        print("FAILED TO FORK!\n");
                    } else if ($pid == 0) {  // child process.
                        pcntl_exec('/usr/bin/pandoc', [ '--metadata', "pagetitle=$page", '--embed-resources', '--standalone', '-f', $from_format, '-t', 'html', '--css=static_files/ghwikipp.css', '--css=static_files/pandoc.css', '--lua-filter=./pandoc-filter-offline.lua', '-o', $dst, $src ]);
                        exit(1);
                    } else {  // parent process.
                        $num_children++;
                        //print("$page\n");
                    }
                }
            }
        }
    }
    closedir($dirp);
}


// Mainline!

date_default_timezone_set('UTC');
$outputname = $wiki_offline_basename;

@mkdir('static_files/offline');  // just in case.

$tmpdir = $offline_data;
$esctmpdir = escapeshellarg($tmpdir);
system("rm -rf $esctmpdir");
if (!mkdir($tmpdir)) {
    print("Failed to mkdir '$tmpdir'\n");
    exit(1);
}

$tmpoutdir = "$tmpdir/$outputname";
$esctmpoutdir = escapeshellarg($tmpoutdir);
if (!mkdir($tmpoutdir)) {
    print("Failed to mkdir '$tmpoutdir'\n");
    exit(1);
}

$tmprawdir = "$tmpdir/raw";
$escrawdir = escapeshellarg($raw_data);
if (!mkdir($tmprawdir)) {
    print("Failed to mkdir '$tmprawdir'\n");
    exit(1);
}

// copy all the raw data elsewhere, so we don't hold the lock longer than
//  necessary. We should probably use rsync to speed this up more.  :)
$git_repo_lock_fp = fopen($repo_lock_fname, 'c+');
if ($git_repo_lock_fp === false) {
    print("Failed to obtain Git repo lock. Please try again later.\n");
    exit(1);
} else if (flock($git_repo_lock_fp, LOCK_EX) === false) {
    print("Exclusive flock of Git repo lock failed. Please try again later.");  // uh...?
    exit(1);
}

$esctmprawdir = escapeshellarg($tmprawdir);
system("cp -R $escrawdir/* $esctmprawdir/");

flock($git_repo_lock_fp, LOCK_UN);
fclose($git_repo_lock_fp);
unset($git_repo_lock_fp);

// okay, now we can operate on this data.
cook_tree_for_offline_html($tmprawdir, $tmpoutdir);
while ($num_children > 0) {
    pcntl_waitpid(-1, $status);  // wait for any child to end.
    $num_children--;
}

copy("$tmpoutdir/FrontPage.html", "$tmpoutdir/index.html");  // make a copy, not a rename, in case something references FrontPage explicitly.
$zipfname = "$outputname.zip";
$esczipfname = escapeshellarg($zipfname);
$escoutputname = escapeshellarg($outputname);
system("cd $esctmpdir && zip -9rq $esczipfname $escoutputname && mv $esczipfname ../static_files/offline/");

