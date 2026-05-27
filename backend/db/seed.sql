DELETE FROM deliveries;
DELETE FROM routes;
DELETE FROM vehicles;

INSERT INTO vehicles (name, capacity, start_lat, start_lng, end_lat, end_lng, status, battery_capacity, current_battery, consumption_rate) VALUES
('Electric Van A', 150.00, 39.865400, 32.735000, 39.865400, 32.735000, 'active', 60.00, 58.00, 0.22),
('Electric Van B', 100.00, 39.865400, 32.735000, 39.865400, 32.735000, 'active', 40.00, 38.00, 0.18),
('Cargo E-Bike C', 40.00, 39.865400, 32.735000, 39.865400, 32.735000, 'active', 2.00, 1.80, 0.02);

INSERT INTO deliveries (customer_name, address, lat, lng, weight, status) VALUES
('Anna Schmidt', 'Beytepe Campus, Ankara', 39.868000, 32.734000, 12.50, 'pending'),
('Bastian Weber', 'Incek Bulvari, Ankara', 39.805200, 32.713600, 45.00, 'pending'),
('Clara Fischer', 'Koru Metro Station, Ankara', 39.889200, 32.701100, 20.00, 'pending'),
('David Wagner', 'Umitkoy, Ankara', 39.900000, 32.700000, 35.50, 'pending'),
('Emily Becker', 'Cayyolu, Ankara', 39.895000, 32.685000, 18.00, 'pending'),
('Felix Hoffmann', 'Bilkent, Ankara', 39.878000, 32.750000, 5.00, 'pending'),
('Greta Schulz', 'Alacaatli, Ankara', 39.862000, 32.680000, 27.00, 'pending'),
('Jonas Meier', 'Yasamkent, Ankara', 39.885000, 32.655000, 15.00, 'pending'),
('Luisa Wagner', 'Angora Evleri, Ankara', 39.892000, 32.725000, 22.00, 'pending'),
('Maximilian Becker', 'Beysukent, Ankara', 39.875000, 32.715000, 10.00, 'pending');

INSERT INTO users (username, password_hash, role, vehicle_id) VALUES
('admin', '$2y$10$vmBS8nTH8xcAKPva3i/NiOFGfneWCUPfAZc/b4VgHj9bqW.8xqANS', 'admin', NULL),
('operator', '$2y$10$GNKclB1LUAdlEaW4XWrh3.yx1Gkphy2JnjC3h/mp8S56fZFqeffGy', 'operator', NULL),
('driver_a', '$2y$10$6TbzjaFYKmmxwURx8H4TMemakcewbAewWLTkYuTFEOLCtgOk2GsBW', 'driver', 1),
('driver_b', '$2y$10$6TbzjaFYKmmxwURx8H4TMemakcewbAewWLTkYuTFEOLCtgOk2GsBW', 'driver', 2);

