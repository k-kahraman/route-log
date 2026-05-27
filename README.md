# RouteLog

RouteLog is an EV first fleet routing and green logistics optimization platform. Designed specifically for carbon-neutral delivery networks, it intelligently distributes delivery jobs across electric vehicle fleets and plans optimal, range-secured routes based on battery constraints and real-world road geography.

## Key Features

- **Battery-Constrained Dispatch (EV-VRP):** Solves the Vehicle Routing Problem (VRP) with electric range security. Intelligently groups and assigns stops ensuring no electric van or cargo e-bike runs out of battery, accounting for depot return legs.
- **Real-Time Eco-Fleet Dashboard:** Visually tracks state of charge (SoC) for active vehicles. Displays estimated battery percentages at each delivery stop and projects route-by-route energy consumption.
- **Green Logistics Analytics:** Automatically calculates energy usage (in kWh) and carbon offsets (CO2 savings in kg) compared to traditional diesel fleets.
- **Advanced Routing Optimization:** Sequences stops using a Nearest Neighbor algorithm refined by a 2-Opt heuristic, minimizing driving distances and conserving battery energy.
- **Interactive Map Interface:** Built with Leaflet, featuring premium light-mode styling, clean EV iconography, double-click/right-click stop creation, and dynamic context actions.
- **Road Geometries & Fallbacks:** Leverages OpenRouteService (ORS) for precise road distance matrices, with an automatic, mathematically sound Haversine fallback.

---

## Technical Architecture

RouteLog is built on a minimalist tech stack:
- **Backend:** PHP with Bramus Router and native PDO.
- **Frontend:** Svelte and Leaflet Map integrations.
- **Database:** PostgreSQL.
- **Deployment:** Fully containerized using Docker and Docker Compose.

---

## Setup & Installation

### Prerequisites
- Docker and Docker Compose

### Step-by-Step Setup

1. **Configure Environment Variables:**
   Review and configure the `.env` file at the root:
   ```bash
   # Add your OpenRouteService API key if using the public API.
   # Leave blank to fall back automatically to local Haversine calculations.
   ORS_API_KEY=your_openrouteservice_key_here
   ORS_BASE_URL=https://api.openrouteservice.org
   ```

2. **Spin Up the Containers:**
   ```bash
   docker compose up --build -d
   ```

3. **Install PHP Dependencies:**
   ```bash
   docker exec -t routelog_backend composer install
   ```

The application is now accessible at:
- **Green Dashboard (Frontend):** `http://localhost:5173`
- **RESTful API Backend:** `http://localhost:8000`

---

## Licensing
Licensed under the [MIT License](LICENSE).
