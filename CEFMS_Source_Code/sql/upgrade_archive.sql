-- Run this once if you already imported an older cefms_demo.sql (adds the Archive feature).
ALTER TABLE event ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
