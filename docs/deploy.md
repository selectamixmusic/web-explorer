# Deploying selectamixmusic.com to server 04

The site runs on Rob's DigitalOcean droplet "server 04" (SSH alias `server04`)
in the layout of the [provision-site toolkit](https://github.com/therobyouknow/infrastructure/tree/main/provision-site/toolkit):
`/var/www/09/selectamixmusic.com/{releases/<version>/code, deployment_environments/live, settings/1, backups, logs}`.
The toolkit commands (`create_release`, `deploy`, `drush_run`, `backup_db`,
`restore_db`, `site_status`) are on the server's PATH.

Secrets live only on the server (`settings/1/settings.local.php`) and in
`docs/private/` here, which git ignores.

## One-off server preparation (done 2026-11-07)

Site structure, database `dselectamix_1`, `settings/1/settings.local.php`,
a self-signed certificate, the Apache vhost `selectamixmusic.com.conf`
(HTTP redirects to HTTPS, `www` redirects to the bare domain) and a
`drush cron` crontab entry every 30 minutes.

## Releasing a new version

```sh
# local: commit, tag, push
git tag -a v0.2.0 -m "0.2.0"
git push origin main --tags

# server: clone the tag into a new release, link settings and files, deploy
ssh server04
create_release selectamixmusic.com 0.2.0 https://github.com/selectamixmusic/web-explorer.git v0.2.0
cd /var/www/09/selectamixmusic.com/releases/0.2.0/code/web/sites/default
ln -sfn ../../../../../../settings/1/settings.local.php settings.local.php
ln -sfn ../../../../../../deployment_environments/live/assets/public/files files
deploy selectamixmusic.com live 0.2.0      # backup_db, composer install, symlink flip, php-fpm reload, updb, cr
drush_run selectamixmusic.com live "cim -y"
drush_run selectamixmusic.com live "core:requirements --severity=1"
```

`create_release` links `settings/2` by default; this site uses database 1,
hence the first `ln`. Rollback: `update_symlink selectamixmusic.com live 0.1.0`,
then `sudo systemctl reload php8.5-fpm` and `drush_run selectamixmusic.com live cr`;
restore the pre-deploy dump with `restore_db` if `updb` changed anything.

## Content and files

Until content is edited on the server, local ddev is the content master:

```sh
ddev export-db --file=/tmp/selectamix.sql.gz
scp /tmp/selectamix.sql.gz server04:/home/rob/
ssh server04 'echo yes | restore_db selectamixmusic.com 1 /home/rob/selectamix.sql.gz && rm /home/rob/selectamix.sql.gz && drush_run selectamixmusic.com live cr'
rsync -avz web/sites/default/files/ server04:/var/www/09/selectamixmusic.com/deployment_environments/live/assets/public/files/
```

Once anyone edits on the server, pull instead:
`fetch_and_import_env_db.sh server04 selectamixmusic.com live`.

## DNS and certificate

`selectamixmusic.com` is registered at Joker.com and still parked there.
To go live: A records for `@` and `www` to `104.248.164.167`, then on the server

```sh
sudo certbot --apache -d selectamixmusic.com -d www.selectamixmusic.com --redirect
```

which replaces the self-signed certificate in the vhost. Until then the site
answers only to requests carrying the host name, e.g.
`curl -k --resolve selectamixmusic.com:443:104.248.164.167 https://selectamixmusic.com/`.
