# Private VCS installation

The public alpha is normally installed from Packagist. This guide is the
alternative for an organization that mirrors GateTrail in a private Git
repository or must install directly from a private fork. Those consumer
environments need read access to that repository before Composer can install
the package.

## Repository access

Prefer SSH authentication. A developer workstation may use the developer's
GitHub SSH key. CI and deployment hosts should use a read-only deploy key or an
equivalent machine identity. Never commit a private key, access token or
credential-bearing repository URL.

Verify access before running Composer:

```bash
ssh -T git@github.com
```

GitHub normally reports that authentication succeeded and that shell access is
not provided. That response is expected.

## Register the private repository

Run this in the consumer application:

```bash
composer config repositories.gatetrail vcs \
    git@github.com:hangtuah/laravel-gatetrail.git
composer require hangtuah/laravel-gatetrail:0.1.0-alpha.3 --prefer-source
```

Commit both `composer.json` and `composer.lock`. The exact alpha version is
intentional: a pilot must not acquire a different pre-release merely because a
new tag was pushed.

## Choose an activation path

### Fresh application

Use Laravel package discovery and follow the normal installation guide:

```bash
php artisan gatetrail:install
php artisan gatetrail:doctor
```

Review the generated configuration before using a shared or production-like
database.

### Existing application with authorization tables

Stage activation when the application already has roles, permissions, Gates,
middleware or tables whose names could overlap GateTrail defaults.

Before requiring GateTrail, add it to Laravel's `dont-discover` list:

```json
{
    "extra": {
        "laravel": {
            "dont-discover": [
                "hangtuah/laravel-gatetrail"
            ]
        }
    }
}
```

Then use this sequence:

1. install the package at the exact alpha tag;
2. publish or prepare `config/gatetrail.php` without migrating the database;
3. assign non-conflicting pilot table names and the intended connection,
   schema, guard and provisioning mode;
4. for DBA-managed provisioning, generate reviewed install and rollback files
   with `php artisan gatetrail:ddl --driver=<driver> --output=<directory>`;
5. provision and verify the isolated pilot schema;
6. register `HangTuah\GateTrail\GateTrailServiceProvider` explicitly;
7. run `php artisan gatetrail:doctor` and `php artisan gatetrail:matrix`;
8. enable one controlled authorization slice through the documented public
   API.

Do not remove the existing authorization implementation during the pilot.
Operate GateTrail in parallel until parity, audit evidence and rollback have
been reviewed.

## Updating or removing the alpha

Update only after choosing and reviewing a newer pre-release explicitly:

```bash
composer require hangtuah/laravel-gatetrail:0.1.0-alpha.3 \
    --prefer-source --with-all-dependencies
```

Removing the Composer dependency does not remove database objects or restore
application authorization behavior. Follow the application's pilot rollback
plan first, then remove the dependency.

