---
status: complete
quick_id: 260803-mbr
date: 2026-08-03
---

# Quick Task 260803-mbr — Summary

Member login → "ACCESS DENIED", stuck, no logout.

## Root cause
`RootController::invoke()` redirected every authenticated user to `/dashboard`
(the admin-only Tyro dashboard). On the normal login flow, a member visits `/`
while logged out → `redirect()->guest('/login')` stores `url.intended = /` →
they log in → `redirect()->intended('/post-login')` resolves that intended and
sends them back to `/` → `RootController` blindly `redirect('/dashboard')` →
`EnsureAnyTyroRole` throws `AuthorizationException('ACCESS DENIED.')` → 403.

`PostLoginRedirectController` (which routes members to `/my`) was bypassed
whenever login was reached via the site root — i.e. the default member path.

This is a **redirect** defect, not a role-assignment defect. Members created via
`MemberController::store` do receive `mess-member` (confirmed: `assignRole`
attaches the role before the `TyroAudit::log()` call that can throw on prod;
verified locally — `hasRole('mess-member')` is true for created members).

## Fix
`app/Http/Controllers/RootController.php` — route authenticated users by role,
mirroring `PostLoginRedirectController`:
- super-admin → `/dashboard` (with onboarding check when no Mess exists)
- manager → `/home`
- mess-member → `/my`
- unrecognized role → `Auth::logout()` + `/login` with an error message
  (no 403, no redirect loop)

## Verification
Repo has no PHPUnit/Pest runner (removed in quick-260724-pm2), so verification
followed the established pattern: live HTTP + tinker + `php -l`.
- `php -l` clean.
- Direct controller invocation: member → `/my` (was `/dashboard`), super-admin →
  `/dashboard`, guest → `/login`.
- End-to-end on a local `php artisan serve` + curl, driving the real member login
  flow through `/`:
  `GET /` (guest, intended stored) → `POST /login` → `/` → `/my` →
  `/my/password/change` → **HTTP 200 "Please set a new password"**.
- Confirmed the old landing still 403s: `GET /dashboard` as the authenticated
  member → **HTTP 403 "ACCESS DENIED"** (the exact original symptom).
- Test member/user accounts created during verification were cleaned up; DB
  restored to its prior 4-user state.

## Commit
- `ef50dc1` fix(auth): route logged-in users by role from '/' (member 403 on login)

## Deploy note
Code-only fix (no migration, no config). Pull + `php artisan optimize:clear`
on prod. No data changes. Members will now reach `/my` (and the first-login
password-change prompt) instead of a 403.
