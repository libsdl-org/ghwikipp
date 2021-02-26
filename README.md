
# ghwikipp

(That's "GitHub Wiki++" because programmers shouldn't name things.)

This is a little glue to make GitHub wikis nicer (I hope).

The idea is that:
- We want to use a git repo for our wiki revision history.
- We mostly want to edit in a web UI, but want the power to edit
text files directly, run scripts over them, etc.
- We want to allow contributions from the outside world with low
overhead, but we also want to avoid spammers.

So this thing runs on your own server, but talks to GitHub through
API calls and repo pushes.

Anyone can read the wiki anonymously. Editing bounces you to GitHub
to log in. If your GitHub account is trusted, edits to the wiki will
be pushed directly to main and show up immediately on the website.
If you aren't a trusted user, your edits become pull requests that
we can examine, comment on, and eventually merge.

If we like your pull requests, we can mark you trusted. If we don't,
we can block you and the wiki won't let you submit any more edits.

All wiki pages are either Markdown or MediaWiki format. An offline
archive of the wiki pages, as HTML, is generated once per day and
available from the website, and the raw markdown pages are just a
simple git clone.

Various pieces of UI are handed off to GitHub (See history of 
changes to a page? You get sent to the log on GitHub. Feedback on
a page without a specific edit? They land in GitHub Issues, etc),
to keep this small and use the existing tools for greater effect.


# To install:

This thing has a lot of moving parts, and setting them up is daunting.
Do your best, ask for help if you need it, correct me if I explained
something incorrectly.

* You'll need a server that can run PHP code (mod_php on Apache or
whatever) that allows you to access the filesystem and run shell
commands on. Git, pandoc, command line PHP, and some other minor Unix
command line tools have to be installed, and accessible to the PHP
code, here. You will absolutely need to be able to serve webpages over
SSL with a valid SSL certificate, as GitHub will require this to
talk to your server.

* We set this up as a new virtual host on Apache that serves wiki
content from the root directory of the host. This probably needs some
bugs fixed to work out of a subdirectory of an existing virtual host.
Our config looks like this... (notably: the first Alias makes one
directory serve static files, and _every other url_ goes through
the PHP script. And, of course, `php_flag engine on` is crucial).

```
ServerAdmin webmaster@libsdl.org
DocumentRoot "/webspace/wiki.libsdl.org"
ServerName wiki.libsdl.org

ErrorLog /var/log/apache2/error_log_wiki_libsdl_org.log
CustomLog /var/log/apache2/access_log_wiki_libsdl_org.log combined

Alias /static_files "/webspace/wiki.libsdl.org/static_files"
Alias / "/webspace/wiki.libsdl.org/index.php/"

<Directory "/webspace/wiki.libsdl.org">
    Options +Indexes +FollowSymLinks
    AllowOverride Limit FileInfo Indexes
    Require all granted
    php_flag engine on
</Directory>
```

* Make a new user on GitHub. This is your wikibot. We called ours
"SDLWikiBot". Give it a legit unused email address you can verify 
from (we'll call it `$WIKIBOT_EMAIL` in shell commands, later).

* Set this new user up in a reasonable way. Go through all the settings.


* Clone the ghwikipp repo to the proper directory on your webserver
(this would be `/webspace/wiki.libsdl.org` in the config above, we'll
call it `$MY_VHOST_ROOT_DIR` here. Set an environment variable.)

```
git clone git clone git@github.com:icculus/ghwikipp.git $MY_VHOST_ROOT_DIR
```

* And clone _your wiki_ under that, in a directory called `raw`:

```
git clone git@github.com:$MY_GITHUB_USER/$MY_WIKI_REPO.git $MY_VHOST_ROOT_DIR/raw
```

* Set up a ssh key for pushing changes to the wiki (hit enter for all questions) ...

```
cd $MY_VHOST_ROOT_DIR
ssh-keygen -t ed25519 -C $WIKIBOT_EMAIL
```

* Print your new ssh public key to the terminal:

```
cat id_ed25519.pub
```

* You should see a line that starts with `ssh-ed25519` and ends with your
wikibot email address.

* Go here logged in as the wikibot: https://github.com/settings/keys

* Click the "New SSH key" button. On the next page, give it whatever you
want for a title, and paste that `ssh-ed25519` line from the terminal
into the "key" textarea, and click "Add SSH key" ... now your wikibot can
push changes to GitHub.

* Go here logged in as the wikibot: https://github.com/settings/tokens

* Click "Generate new token". Make the note "Wiki API access" or whatever,
click "repo" in the scopes section so it fills in a bunch of checkboxes
under it. Scroll down and click the "generate token" button. It will
show you a long string of characters. _Copy this somewhere_ as it won't
be shown to you again.

* Log out as wikibot, you're done with this for the moment.

* Go back to _your normal_ GitHub account, and visit the "settings" page
on the new wiki project. Click on "Manage access" and invite the wikibot
to this project with write access. Then, logged in as the wikibot, accept
the invitation (which might require you to click a link in an email sent
to the wikibot's address). Now the bot can push changes to this repo.

* From your GitHub organization's settings page (or if you don't have an
organization, your personal settings page), click on "Developer settings"
and then "OAuth Apps". Click "Register an application". Put the name in
as whatever (this will be shown to end users, so "MyProject's Wiki" is
probably good). "Homepage URL" should be your virtual host's URL.
"Application description" should be something like "This application
verifies your GitHub username before you are allowed to make changes to
MyProject's wiki." Set the "Callback URL" to the virtual host's URL, and
click "Create application."

* On the next page, click "Generate client secret" and _copy the string
it gives you_ ... you can't get it again later. Also copy down the
"Client ID" string.

* On your wiki's project page, click "settings", then "webhooks", then
"add webhook". For this, set the "Payload URL" to "github/webhook" on
your virtual host's address (so, for wiki.libsdl.org, this would be
set to `https://wiki.libsdl.org/github/webhook`). "Content-Type" should
be set to "application/json" ... You can write anything in the "Secret"
field as long as it's secret. A long string of numbers and letters,
doesn't matter, just copy it somewhere because your server needs to know
it too. Select "just the push event" for which events you want sent, and
click "Add webhook".

* Back in your virtual host's directory, copy the sample config...

```
cp config.php.sample config.php
```

* ...and then edit config.php to fit your needs. All those strings you
were supposed to copy, above? They go in this file.

* Copy your own logo and favicon.ico to the "static_files" directory.

* Set up a cronjob to build the offline archive once a day:

```
sudo ln -s $MY_VHOST_ROOT_DIR/make_offline_archive.php /etc/cron.daily/build_offline_wiki_archive
```

* Make sure everything is fully readable/writable by the webserver user:

```
chown -R www-data:www-data $MY_VHOST_ROOT_DIR
```

(if you don't have root access, you might be able to set up a cronjob
for your specific user. Nothing about this _needs_ root privileges.
Read the manual.  :) )

* Okay, now we're getting somewhere! Go to your vhost in a web browser
and see if it chokes. It will probably complain that FrontPage is
missing. Click "edit" ... you should be tossed over to github to verify
you're a real person. Permit authorization and you'll be back to edit
the wiki page. (unless you revoke this authorization in GitHub's settings
or log out, you won't have to see GitHub again for reauthorization).

Just write "hello world" and save it. If you didn't set yourself up as
an admin, you should end up with a pull request on the wiki github repo,
otherwise you'll get a commit pushed to main (master, whatever) for your
new change. This will fire GitHub's webhook, which will cause your server
to recook all the existing wiki data on this first run. If you have a
big wiki, give it a few minutes. Otherwise, you should be good to go.
Mark a few people as admins, so they can mark people as trusted to make
direct pushes instead of pull requests, as appropriate.



