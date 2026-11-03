-- CEFMS database schema (MySQL / MariaDB)
-- Cultural Events and Festivals Management System
CREATE DATABASE IF NOT EXISTS cefms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cefms;

DROP TABLE IF EXISTS payment;
DROP TABLE IF EXISTS reservation;
DROP TABLE IF EXISTS schedule;
DROP TABLE IF EXISTS event;
DROP TABLE IF EXISTS user;

CREATE TABLE user (
  userId       INT AUTO_INCREMENT PRIMARY KEY,
  fullName     VARCHAR(100) NOT NULL,
  email        VARCHAR(120) NOT NULL UNIQUE,
  phone        VARCHAR(20)  NOT NULL,
  passwordHash VARCHAR(255) NOT NULL,
  role         ENUM('Event Participant','Organizer','Event Manager') NOT NULL,
  status       ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  createdDate  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE event (
  eventId        INT AUTO_INCREMENT PRIMARY KEY,
  organizerId    INT NOT NULL,
  title          VARCHAR(150) NOT NULL,
  description    TEXT NOT NULL,
  category       ENUM('Music','Dance','Craft','Food','Religious','Multi-cultural') NOT NULL,
  ticketPrice    DECIMAL(8,2) NOT NULL,
  totalSeats     INT NOT NULL,
  availableSeats INT NOT NULL,
  status         ENUM('Pending','Published','Cancelled','Completed') NOT NULL DEFAULT 'Pending',
  archived       TINYINT(1) NOT NULL DEFAULT 0,
  createdDate    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_event_org FOREIGN KEY (organizerId) REFERENCES user(userId),
  CONSTRAINT chk_seats CHECK (availableSeats >= 0 AND availableSeats <= totalSeats),
  CONSTRAINT chk_price CHECK (ticketPrice >= 0)
) ENGINE=InnoDB;

CREATE TABLE schedule (
  scheduleId    INT AUTO_INCREMENT PRIMARY KEY,
  eventId       INT NOT NULL,
  venue         VARCHAR(120) NOT NULL,
  startDateTime DATETIME NOT NULL,
  endDateTime   DATETIME NOT NULL,
  status        ENUM('Scheduled','Rescheduled','Cancelled') NOT NULL DEFAULT 'Scheduled',
  CONSTRAINT fk_sched_event FOREIGN KEY (eventId) REFERENCES event(eventId) ON DELETE CASCADE,
  CONSTRAINT chk_time CHECK (endDateTime > startDateTime),
  INDEX idx_sched_venue (venue, startDateTime, endDateTime)
) ENGINE=InnoDB;

CREATE TABLE reservation (
  reservationId INT AUTO_INCREMENT PRIMARY KEY,
  userId        INT NOT NULL,
  eventId       INT NOT NULL,
  quantity      INT NOT NULL,
  totalAmount   DECIMAL(10,2) NOT NULL,
  reservedDate  DATETIME NOT NULL,
  expiryDate    DATETIME NOT NULL,
  ticketCode    VARCHAR(20) NULL UNIQUE,
  status        ENUM('Reserved','Paid','Cancelled','Expired') NOT NULL DEFAULT 'Reserved',
  CONSTRAINT fk_res_user  FOREIGN KEY (userId)  REFERENCES user(userId),
  CONSTRAINT fk_res_event FOREIGN KEY (eventId) REFERENCES event(eventId),
  CONSTRAINT chk_qty CHECK (quantity BETWEEN 1 AND 10)
) ENGINE=InnoDB;

CREATE TABLE payment (
  paymentId      INT AUTO_INCREMENT PRIMARY KEY,
  reservationId  INT NOT NULL,
  amount         DECIMAL(10,2) NOT NULL,
  method         ENUM('Online Banking','Card','E-Wallet') NOT NULL,
  transactionRef VARCHAR(40) NOT NULL,
  paymentDate    DATETIME NOT NULL,
  status         ENUM('Pending','Successful','Failed') NOT NULL,
  CONSTRAINT fk_pay_res FOREIGN KEY (reservationId) REFERENCES reservation(reservationId)
) ENGINE=InnoDB;
