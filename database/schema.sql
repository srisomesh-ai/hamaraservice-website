-- ============================================================
-- HamaraService — MySQL Database Schema
-- Hostinger MySQL
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';

-- ── 1. CUSTOMERS ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS customers (
  id            VARCHAR(64)  PRIMARY KEY,        -- Firebase Auth UID
  name          VARCHAR(100) NOT NULL,
  phone         VARCHAR(15),
  email         VARCHAR(100),
  gender        VARCHAR(10),
  address       TEXT,
  city          VARCHAR(60),
  lat           DECIMAL(10,7),
  lng           DECIMAL(10,7),
  fcm_token     TEXT,
  auth_method   VARCHAR(20)  DEFAULT 'email',    -- email / google
  created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_email (email),
  INDEX idx_phone (phone),
  INDEX idx_city  (city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 2. PROVIDERS ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS providers (
  id            VARCHAR(30)  PRIMARY KEY,        -- e.g. HS-PRO-SOMA1234
  name          VARCHAR(100) NOT NULL,
  phone         VARCHAR(15)  NOT NULL,
  email         VARCHAR(100) NOT NULL,
  whatsapp      VARCHAR(15),
  password_hash VARCHAR(255) NOT NULL,           -- bcrypt hashed
  gender        VARCHAR(10),
  experience    VARCHAR(30),
  bio           TEXT,
  id_type       VARCHAR(30),
  id_number     VARCHAR(50),
  address       TEXT,
  city          VARCHAR(60),
  lat           DECIMAL(10,7),
  lng           DECIMAL(10,7),
  radius_km     INT          DEFAULT 5,
  status        ENUM('pending','approved','suspended','rejected') DEFAULT 'pending',
  available     TINYINT(1)   DEFAULT 0,
  rating        DECIMAL(3,1) DEFAULT 0,
  review_count  INT          DEFAULT 0,
  total_bookings      INT    DEFAULT 0,
  completed_bookings  INT    DEFAULT 0,
  total_earned        INT    DEFAULT 0,
  pending_earned      INT    DEFAULT 0,
  fcm_token     TEXT,
  registered_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_email  (email),
  INDEX idx_city   (city),
  INDEX idx_status (status),
  INDEX idx_latlng (lat, lng)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 3. PROVIDER SERVICES ────────────────────────────────────
-- Each provider can offer multiple services with their own min/max price
CREATE TABLE IF NOT EXISTS provider_services (
  id          INT          AUTO_INCREMENT PRIMARY KEY,
  provider_id VARCHAR(30)  NOT NULL,
  svc_id      VARCHAR(10)  NOT NULL,             -- e.g. SVC001
  svc_name    VARCHAR(100),
  svc_icon    VARCHAR(10),
  svc_cat     VARCHAR(50),
  enabled     TINYINT(1)   DEFAULT 1,
  min_price   INT          DEFAULT 0,
  max_price   INT          DEFAULT 0,
  FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
  UNIQUE KEY unique_provider_svc (provider_id, svc_id),
  INDEX idx_svc_id (svc_id),
  INDEX idx_provider (provider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 4. SERVICE CATALOG ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS services (
  id           VARCHAR(10)  PRIMARY KEY,         -- SVC001..SVC034
  name         VARCHAR(100) NOT NULL,
  icon         VARCHAR(10),
  category     VARCHAR(50),
  base_price   INT          DEFAULT 0,
  description  TEXT,
  is_active    TINYINT(1)   DEFAULT 1,
  sort_order   INT          DEFAULT 0,
  created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 5. SERVICE PRICES (reference prices set by admin) ───────
CREATE TABLE IF NOT EXISTS service_prices (
  id           INT          AUTO_INCREMENT PRIMARY KEY,
  svc_id       VARCHAR(10)  NOT NULL,
  group_key    VARCHAR(50),                       -- e.g. 'bhk', 'task'
  option_key   VARCHAR(50),                       -- e.g. '1bhk', 'sweep'
  option_name  VARCHAR(100),
  price        INT          DEFAULT 0,
  FOREIGN KEY (svc_id) REFERENCES services(id) ON DELETE CASCADE,
  INDEX idx_svc (svc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 6. BOOKINGS ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bookings (
  id                VARCHAR(30)  PRIMARY KEY,
  customer_id       VARCHAR(64)  NOT NULL,
  customer_name     VARCHAR(100),
  customer_phone    VARCHAR(15),
  provider_id       VARCHAR(30),
  provider_name     VARCHAR(100),
  svc_id            VARCHAR(10),
  svc_name          VARCHAR(100),
  svc_icon          VARCHAR(10),
  address           TEXT,
  city              VARCHAR(60),
  lat               DECIMAL(10,7),
  lng               DECIMAL(10,7),
  slot_date         DATE,
  slot_time         VARCHAR(20),
  notes             TEXT,
  status            VARCHAR(30)  DEFAULT 'active',
  -- Price negotiation fields
  quoted_price      INT          DEFAULT 0,
  counter_price     INT          DEFAULT 0,
  final_price       INT          DEFAULT 0,
  confirmed_price   INT          DEFAULT 0,
  negotiation_status VARCHAR(30) DEFAULT NULL,
  -- Payment
  payment_method    VARCHAR(20),
  payment_status    VARCHAR(20)  DEFAULT 'pending',
  razorpay_order_id VARCHAR(100),
  razorpay_payment_id VARCHAR(100),
  amount            INT          DEFAULT 0,
  commission_pct    INT          DEFAULT 15,
  commission_amt    INT          DEFAULT 0,
  provider_earns    INT          DEFAULT 0,
  -- OTP
  otp               VARCHAR(10),
  otp_verified      TINYINT(1)   DEFAULT 0,
  -- Timestamps
  created_at        DATETIME     DEFAULT CURRENT_TIMESTAMP,
  accepted_at       DATETIME,
  completed_at      DATETIME,
  updated_at        DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  INDEX idx_customer   (customer_id),
  INDEX idx_provider   (provider_id),
  INDEX idx_status     (status),
  INDEX idx_city       (city),
  INDEX idx_created    (created_at),
  INDEX idx_svc        (svc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 7. REVIEWS ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reviews (
  id           INT          AUTO_INCREMENT PRIMARY KEY,
  booking_id   VARCHAR(30)  NOT NULL,
  customer_id  VARCHAR(64)  NOT NULL,
  provider_id  VARCHAR(30)  NOT NULL,
  rating       INT          NOT NULL CHECK (rating BETWEEN 1 AND 5),
  comment      TEXT,
  created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  INDEX idx_provider (provider_id),
  INDEX idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 8. PAYOUTS ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS payouts (
  id           INT          AUTO_INCREMENT PRIMARY KEY,
  provider_id  VARCHAR(30)  NOT NULL,
  amount       INT          NOT NULL,
  account_type VARCHAR(20),                      -- upi / bank
  upi_id       VARCHAR(100),
  bank_name    VARCHAR(100),
  account_no   VARCHAR(30),
  ifsc         VARCHAR(15),
  status       ENUM('pending','approved','rejected') DEFAULT 'pending',
  note         TEXT,
  requested_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME,
  FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
  INDEX idx_provider (provider_id),
  INDEX idx_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 9. FCM NOTIFICATIONS LOG (optional but useful) ──────────
CREATE TABLE IF NOT EXISTS notifications (
  id           INT          AUTO_INCREMENT PRIMARY KEY,
  recipient_id VARCHAR(64)  NOT NULL,            -- customer or provider id
  recipient_type VARCHAR(10),                    -- customer / provider
  event        VARCHAR(50),
  title        VARCHAR(200),
  body         TEXT,
  data         JSON,
  sent_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_recipient (recipient_id),
  INDEX idx_event     (event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 10. SEED: 34 SERVICES ───────────────────────────────────
INSERT IGNORE INTO services (id, name, icon, category, sort_order) VALUES
('SVC001','House Maid','🧹','Home Cleaning',1),
('SVC002','Deep Cleaning','🧽','Home Cleaning',2),
('SVC003','Bathroom Cleaning','🚿','Home Cleaning',3),
('SVC004','Kitchen Cleaning','🍳','Home Cleaning',4),
('SVC005','Sofa / Carpet Cleaning','🛋️','Home Cleaning',5),
('SVC006','Laundry / Ironing','👕','Home Cleaning',6),
('SVC007','AC Cleaning & Repair','❄️','Home Services',7),
('SVC008','AC Repair','🔧','Home Services',8),
('SVC009','Home Appliance Repair','🔌','Home Services',9),
('SVC010','Water Purifier Service','💧','Home Services',10),
('SVC011','Plumber','🪠','Home Services',11),
('SVC012','Electrician','⚡','Home Services',12),
('SVC013','Carpenter','🪚','Home Services',13),
('SVC014','Painter','🎨','Home Services',14),
('SVC015','CCTV Installation','📹','Home Services',15),
('SVC016','Solar Panel Cleaning','☀️','Home Services',16),
('SVC017','Car / Bike Wash','🚗','Vehicle Care',17),
('SVC018','Bike Wash','🏍️','Vehicle Care',18),
('SVC019','Car & Bike Mechanic','🔧','Vehicle Care',19),
('SVC020','2 Wheeler Mechanic','🔩','Vehicle Care',20),
('SVC021','Pest Control','🪲','Pest Control',21),
('SVC022','Cook / Cooking Person','👨‍🍳','Cooking',22),
('SVC023','Men''s Haircut at Home','✂️','Beauty & Wellness',23),
('SVC024','Women''s Haircut & Beauty','💇','Beauty & Wellness',24),
('SVC025','Full Body Massage','💆','Beauty & Wellness',25),
('SVC026','Gym / Fitness Trainer','💪','Beauty & Wellness',26),
('SVC027','Doctor Visit at Home','👨‍⚕️','Health Services',27),
('SVC028','Nurse Visit at Home','💉','Health Services',28),
('SVC029','Lab Test Collection','🧪','Health Services',29),
('SVC030','Babysitter / Nanny','👶','Care Services',30),
('SVC031','Elderly Care','🧓','Care Services',31),
('SVC032','Gardener','🌱','Outdoor',32),
('SVC033','Driver','🚕','Outdoor',33),
('SVC034','Security Guard & Bouncers','🛡️','Security',34);
