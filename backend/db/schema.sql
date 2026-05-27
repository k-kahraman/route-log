DROP TABLE IF EXISTS user_sessions CASCADE;
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS deliveries CASCADE;
DROP TABLE IF EXISTS routes CASCADE;
DROP TABLE IF EXISTS vehicles CASCADE;


CREATE TABLE vehicles (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    capacity NUMERIC(10, 2) NOT NULL, -- in kg
    start_lat DOUBLE PRECISION NOT NULL,
    start_lng DOUBLE PRECISION NOT NULL,
    end_lat DOUBLE PRECISION NOT NULL,
    end_lng DOUBLE PRECISION NOT NULL,
    status VARCHAR(20) DEFAULT 'active' NOT NULL,
    battery_capacity NUMERIC(10, 2) NOT NULL DEFAULT 50.00, -- in kWh
    current_battery NUMERIC(10, 2) NOT NULL DEFAULT 50.00, -- in kWh
    consumption_rate NUMERIC(10, 2) NOT NULL DEFAULT 0.20 -- in kWh/km
);

CREATE TABLE routes (
    id SERIAL PRIMARY KEY,
    vehicle_id INT NOT NULL REFERENCES vehicles(id) ON DELETE CASCADE,
    total_distance NUMERIC(10, 2) NOT NULL DEFAULT 0.0, -- in meters
    total_duration NUMERIC(10, 2) NOT NULL DEFAULT 0.0, -- in seconds
    geometry TEXT,
    routing_source VARCHAR(100),
    traffic_source VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE TABLE deliveries (
    id SERIAL PRIMARY KEY,
    customer_name VARCHAR(150) NOT NULL,
    address VARCHAR(255) NOT NULL,
    lat DOUBLE PRECISION NOT NULL,
    lng DOUBLE PRECISION NOT NULL,
    weight NUMERIC(10, 2) NOT NULL, -- in kg
    status VARCHAR(20) DEFAULT 'pending' NOT NULL,
    route_id INT REFERENCES routes(id) ON DELETE SET NULL,
    sequence_number INT
);

CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL CHECK (role IN ('driver', 'operator', 'admin')),
    vehicle_id INT REFERENCES vehicles(id) ON DELETE SET NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL
);

CREATE TABLE user_sessions (
    token VARCHAR(64) PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL
);

