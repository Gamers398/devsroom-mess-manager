# Quick Task 260803-mbr — Plan

**Created:** 2026-08-03
**Description:** Mess member logs in and gets "ACCESS DENIED", can't logout or do anything.

## Symptom
Manager adds a member with a password. Member goes to login, enters email + password, sees "ACCESS DENIED" and is stuck (no logout, no navigation).

## Root cause (investigated inline)
`RootController` (the `/` route) redirected **every** authenticated user to `/dashboard` — the admin-only Tyro dashboard (`roles:super-admin,manager`). The normal member login flow is:

1. Logged-out member visits `/` → `redirect()->guest('/login')` stores `url.intended = /`.
2. Member logs in → `redirect()->intended('/post-login')` resolves the stored intended → goes back to `/`.
3. `RootController` sees a logged-in user → `redirect('/dashboard')`.
4. `/dashboard` is admin-only → `EnsureAnyTyroRole` throws `AuthorizationException('ACCESS DENIED.')` → 403, stuck.

`PostLoginRedirectController` (which correctly sends members to `/my`) is bypassed whenever login is reached via the site root — i.e. the default path.

Not a role-assignment defect: members created via `MemberController::store` DO receive `mess-member` (confirmed: assignRole succeeds, role attaches before the audit log that can throw on prod). The 403 comes from the redirect, not a missing role.

## Fix (single file)
`app/Http/Controllers/RootController.php` — route authenticated users by role, mirroring `PostLoginRedirectController`:
- super-admin → `/dashboard` (with onboarding check if no Mess)
- manager → `/home`
- mess-member → `/my`
- unrecognized role → logout + `/login` with a message (no 403, no loop)

## Verification (repo has no PHPUnit runner — pattern: live HTTP + tinker + php -l)
- `php -l` clean.
- Direct controller call: member → `/my`, super-admin → `/dashboard`, guest → `/login` (was member → `/dashboard`).
- End-to-end (local `artisan serve` + curl, full member login via `/`): lands on `/my/password/change` HTTP 200 ("Please set a new password"), NOT a 403.
- Confirmed the old landing `/dashboard` still returns 403 ACCESS DENIED for a member (reproduces the original symptom).
