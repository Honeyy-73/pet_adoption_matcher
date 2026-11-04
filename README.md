# Pet Adoption Matcher (IM2 Final Project)

PHP 8 + MySQL/MariaDB on XAMPP. Retro pixel-window UI.

## Setup (5 steps)
1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Copy this folder to `C:\xampp\htdocs\pet_adoption_matcher`.
3. Open phpMyAdmin (`http://localhost/phpmyadmin`) -> **Import** -> choose `database.sql` -> **Go**.
   (Creates the `pet_adoption_matcher` database with 7 tables, 2 views, 1 function, 3 procedures, 4 triggers, 3 indexes, sample pets/adopters.)
4. Open `http://localhost/pet_adoption_matcher/setup.php` once. It creates the default accounts and sample adoptions. Then **delete setup.php**.
5. Open `http://localhost/pet_adoption_matcher/` and log in (or use *Sign up* to make a Visitor account).

If your MySQL root has a password, or XAMPP is not in `C:\xampp`, edit `config.php`.

## Default logins
| Role | Username | Password | Can do |
|---|---|---|---|
| Admin | admin | Admin@123 | Users (creates Staff/Admin accounts), reports and audit log, backup/restore |
| Staff | staff | Staff@123 | Pets, adopters, adoption applications and decisions |
| Visitor | visitor | Visitor@123 | Browse pets, find matches, create own profile, **apply to adopt**, track own applications |

**Visitor flow:** Sign up -> My profile (housing, kids, activity, preferred pet) -> Browse / Find matches (ranked by match %) -> *Apply to adopt* -> My applications. Staff then approve or reject. A visitor can have at most 3 pending applications and can only apply as their own profile (`adopters.user_id`).

**Sign up:** the login page has a *Sign up* link. Self-registered accounts are always **Visitor**. Only an Admin can create Staff or Admin accounts (Users page).

**Username rules:** 4-20 characters, starts with a letter, only letters / numbers / underscore, no spaces.
**Password rules:** 8-64 characters, at least one letter and one number, **no spaces**.

Roles are enforced on every page with `require_role()` (server side), not just hidden menus.

## Adoption flow (Visitor -> Staff)
1. Visitor signs up, fills in **My profile** (housing, kids, other pets, activity, preferred pet).
2. **Find matches** ranks available pets (prefilled from the profile). **Browse pets** lists them all.
3. Visitor presses **Apply to adopt** -> `sp_submit_application` (Transaction 1) saves a Pending application and sets the pet to Pending.
4. Staff see it on the **Adoption** page and Approve or Reject -> `sp_decide_application` (Transaction 2).
5. Visitor sees the result under **My applications**. Staff can still enter walk-in adopters (no user account) themselves.

Schema change vs. the ERD: `adopters.user_id` (nullable, unique, FK to users) links a Visitor account to their adopter profile.

## Where each requirement lives
| Requirement | Where |
|---|---|
| 7 tables, junction (`pet_traits`), self-reference (`users.supervisor_id`) | `database.sql` |
| 3 stored procedures | `sp_submit_application`, `sp_decide_application`, `sp_rank_matches` |
| 4 triggers (audit) | `trg_applications_ai/au`, `trg_pets_ad`, `trg_adopters_ad` |
| 2 views | `v_available_pets`, `v_application_details` |
| User-defined function | `fn_match_score` |
| CTE + window function | `sp_rank_matches` (`WITH scored AS ... RANK() OVER`) |
| GROUP BY / HAVING / JOINs / subqueries | `reports.php`, `v_available_pets` |
| 2 transactions (COMMIT/ROLLBACK, row locks) | `sp_submit_application`, `sp_decide_application` |
| Password hashing | `password_hash()` / `password_verify()` in `users.php`, `login.php`, `setup.php` |
| Prepared statements | every query goes through `q()` in `includes/bootstrap.php` |
| Encryption at rest | `adopters.contact_no` uses `AES_ENCRYPT` / `AES_DECRYPT` |
| Audit log | triggers + `audit()`, viewed in `reports.php` |
| Backup / restore | `backup.php` (mysqldump / mysql) |
| Indexes + before/after | `performance_test.sql` |
| OLAP report | `reports.php` (adoptions per month) |

## Demo scripts for the defense
**SQL injection (before/after).** At the login username type `' OR '1'='1' -- `.
- Before (vulnerable, for explanation only): `"SELECT * FROM users WHERE username = '$username'"` becomes `... WHERE username = '' OR '1'='1' -- '` and returns every user.
- After (this project): `q('SELECT ... WHERE username = ?', [$username])` treats the whole text as one value, so login fails with "Wrong username or password".

**Concurrency / lost update.** Open two browser windows as `staff`. In both, choose the same Available pet and press *Submit application* at nearly the same time. `sp_submit_application` locks the pet row with `SELECT ... FOR UPDATE`, so the second call waits, then sees status `Pending` and returns "This pet is not available anymore". Only one application is saved.

**ACID summary.** Atomicity: insert application + set pet Pending happen together or are rolled back. Consistency: FK, CHECK and ENUM constraints plus the status rules in the procedures. Isolation: row locks (`FOR UPDATE`) under InnoDB. Durability: committed InnoDB data survives a restart; backups via mysqldump.

**Backup demo.** Log in as admin -> Backup -> Download. Delete a pet in phpMyAdmin (simulated data loss) -> Backup page -> Restore with the file -> the pet is back.

**Performance.** Run `performance_test.sql` in the phpMyAdmin SQL tab and record the EXPLAIN rows and query time before/after the index.

## Match score (fn_match_score, 0-100)
Species 30 + energy 30 (15 if one step apart) + kids 15 + other pets 10 + housing 15.

## Notes
- Needs PHP 8.0+ (XAMPP 8.x). Pixel font loads from Google Fonts and falls back to a monospace font offline.
- This is a school project: for real deployment use HTTPS, a non-root DB user, and move `ENC_KEY` out of the code.
