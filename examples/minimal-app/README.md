# GateTrail minimal consumer application

This retained Laravel 13 application proves that GateTrail can be consumed as
a package without importing any internal class. It uses SQLite so the example
is fast and self-contained; it does not expand GateTrail's production-database
compatibility claims.

From this directory:

```bash
composer install
composer test
```

Composer installs `hangtuah/laravel-gatetrail` from the repository root through
a path repository. Laravel package discovery registers GateTrail. The feature
test then runs the complete first vertical slice:

```text
Migrate a fresh application
→ create a role and permission
→ grant the permission to the role
→ assign the role to a persisted user
→ authorize through Laravel Gate and route middleware
→ revoke the role assignment
→ deny the same Gate and route checks
→ retain assignment history
→ verify actor and reason on every audit record
```

The application intentionally contains only the files needed for that flow. It
is executable evidence and a learning aid, not a production starter kit.
