-- 013_create_ratings.sql
CREATE TABLE IF NOT EXISTS ratings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ride_id BINARY(16) NOT NULL,
  driver_id BIGINT NOT NULL,
  customer_id BINARY(16) NOT NULL,
  rating TINYINT NOT NULL,
  comment VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ratings_ride FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE,
  CONSTRAINT fk_ratings_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ratings_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_ratings_driver (driver_id),
  INDEX idx_ratings_customer (customer_id)
) ENGINE=InnoDB;

