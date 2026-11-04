-- =====================================================================
-- Performance test: before / after index  (run in phpMyAdmin > SQL tab)
-- The real pets table has only a few rows, so we build 100,000 rows in a
-- separate scratch table to make the difference visible.
-- Run each numbered step and note "rows" in EXPLAIN and the
-- "Query took ... seconds" message phpMyAdmin shows.
-- =====================================================================
USE pet_adoption_matcher;

DROP TABLE IF EXISTS pets_perf;
DROP TABLE IF EXISTS digits_tmp;
CREATE TABLE digits_tmp (n INT);
INSERT INTO digits_tmp VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9);

CREATE TABLE pets_perf (
  pet_id  INT AUTO_INCREMENT PRIMARY KEY,
  name    VARCHAR(50),
  species VARCHAR(10),
  status  VARCHAR(10)
);

INSERT INTO pets_perf (name, species, status)
SELECT CONCAT('Pet ', x.n), IF(x.n % 2 = 0, 'Dog', 'Cat'), IF(x.n % 50 = 0, 'Available', 'Adopted')
FROM (SELECT a.n + 10*b.n + 100*c.n + 1000*d.n + 10000*e.n AS n
        FROM digits_tmp a, digits_tmp b, digits_tmp c, digits_tmp d, digits_tmp e) x;

-- 1) BEFORE the index (expect type = ALL, rows ~ 100000)
EXPLAIN SELECT pet_id, name FROM pets_perf WHERE status = 'Available' AND species = 'Dog';
SELECT COUNT(*) FROM pets_perf WHERE status = 'Available' AND species = 'Dog';

-- 2) create the same kind of index used in database.sql
CREATE INDEX idx_perf_status_species ON pets_perf(status, species);

-- 3) AFTER the index (expect type = ref, rows ~ 1000 or fewer)
EXPLAIN SELECT pet_id, name FROM pets_perf WHERE status = 'Available' AND species = 'Dog';
SELECT COUNT(*) FROM pets_perf WHERE status = 'Available' AND species = 'Dog';

-- 4) clean up
DROP TABLE pets_perf;
DROP TABLE digits_tmp;
