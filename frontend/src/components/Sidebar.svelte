<script>
  let {
    currentUser = null,
    vehicles = [],
    deliveries = [],
    routes = [],
    usersList = [],
    sidebarMinimized = false,
    onAddVehicle,
    onDeleteVehicle,
    onAddDelivery,
    onDeleteDelivery,
    onUpdateDeliveryStatus,
    onMoveDeliveryToVehicle,
    onReorderRouteStops,
    onAddUser,
    onDeleteUser,
    onOptimize,
    onToggleSidebar,
    isOptimizing = false,
    optimizationSummary = null
  } = $props();

  let activeTab = $state('deliveries');

  // Form states - Vehicle
  let vehicleName = $state('');
  let vehicleCapacity = $state(100.0);
  let depotLat = $state(39.865400);
  let depotLng = $state(32.735000);
  let vehicleBatteryCapacity = $state(50.0);
  let vehicleCurrentBattery = $state(50.0);
  let vehicleConsumptionRate = $state(0.20);

  // Form states - Delivery
  let clientName = $state('');
  let addressText = $state('');
  let deliveryWeight = $state(10.0);
  let deliveryLat = $state(39.865400);
  let deliveryLng = $state(32.735000);

  // Form states - User (Admin only)
  let newUsername = $state('');
  let newPassword = $state('');
  let newRole = $state('driver');
  let newVehicleId = $state('');

  function handleAddVehicle(e) {
    e.preventDefault();
    if (!vehicleName.trim() || vehicleCapacity <= 0) return;
    
    onAddVehicle({
      name: vehicleName,
      capacity: parseFloat(vehicleCapacity),
      start_lat: parseFloat(depotLat),
      start_lng: parseFloat(depotLng),
      end_lat: parseFloat(depotLat),
      end_lng: parseFloat(depotLng),
      battery_capacity: parseFloat(vehicleBatteryCapacity),
      current_battery: parseFloat(vehicleCurrentBattery),
      consumption_rate: parseFloat(vehicleConsumptionRate)
    });

    vehicleName = '';
  }

  function handleAddDelivery(e) {
    e.preventDefault();
    if (!clientName.trim() || !addressText.trim() || deliveryWeight <= 0) return;

    onAddDelivery({
      customer_name: clientName,
      address: addressText,
      lat: parseFloat(deliveryLat),
      lng: parseFloat(deliveryLng),
      weight: parseFloat(deliveryWeight)
    });

    clientName = '';
    addressText = '';
  }

  function handleAddUser(e) {
    e.preventDefault();
    if (!newUsername.trim() || !newPassword.trim() || !newRole) return;

    onAddUser({
      username: newUsername,
      password: newPassword,
      role: newRole,
      vehicle_id: newRole === 'driver' && newVehicleId ? parseInt(newVehicleId) : null
    });

    newUsername = '';
    newPassword = '';
    newRole = 'driver';
    newVehicleId = '';
  }

  export function prefillDeliveryCoords(lat, lng) {
    deliveryLat = parseFloat(lat.toFixed(6));
    deliveryLng = parseFloat(lng.toFixed(6));
    activeTab = 'deliveries';
  }

  function formatDistance(meters) {
    if (meters >= 1000) {
      return (meters / 1000).toFixed(2) + ' km';
    }
    return Math.round(meters) + ' m';
  }

  function formatDuration(seconds) {
    const mins = Math.round(seconds / 60);
    if (mins >= 60) {
      const hrs = Math.floor(mins / 60);
      const remainingMins = mins % 60;
      return `${hrs}h ${remainingMins}m`;
    }
    return `${mins}m`;
  }

  function calculateBatteryProgression(routeData) {
    const vehicle = routeData.vehicle;
    const deliveries = routeData.deliveries;
    const totalDistance = parseFloat(routeData.route.total_distance);

    if (!vehicle || !vehicle.consumption_rate || deliveries.length === 0) {
      return [];
    }

    const consumptionRate = parseFloat(vehicle.consumption_rate);
    const initialBattery = parseFloat(vehicle.current_battery);
    const capacity = parseFloat(vehicle.battery_capacity);

    const coords = [
      { lat: parseFloat(vehicle.start_lat), lng: parseFloat(vehicle.start_lng) },
      ...deliveries.map(d => ({ lat: parseFloat(d.lat), lng: parseFloat(d.lng) })),
      { lat: parseFloat(vehicle.end_lat), lng: parseFloat(vehicle.end_lng) }
    ];

    const haversineDistance = (lat1, lng1, lat2, lng2) => {
      const R = 6371;
      const dLat = (lat2 - lat1) * Math.PI / 180;
      const dLng = (lng2 - lng1) * Math.PI / 180;
      const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                Math.sin(dLng/2) * Math.sin(dLng/2);
      const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
      return R * c;
    };

    let haversineLegs = [];
    let totalHaversine = 0;
    for (let i = 0; i < coords.length - 1; i++) {
      const d = haversineDistance(coords[i].lat, coords[i].lng, coords[i+1].lat, coords[i+1].lng);
      haversineLegs.push(d);
      totalHaversine += d;
    }

    const totalDistanceKm = totalDistance / 1000;
    const scalingFactor = totalHaversine > 0 ? totalDistanceKm / totalHaversine : 1;

    let currentBattery = initialBattery;
    let progression = [];

    for (let i = 0; i < deliveries.length; i++) {
      const legDistKm = haversineLegs[i] * scalingFactor;
      const energyUsed = legDistKm * consumptionRate;
      currentBattery = Math.max(0, currentBattery - energyUsed);
      const pct = Math.round((currentBattery / capacity) * 100);
      progression.push({
        batteryKwh: currentBattery.toFixed(1),
        batteryPct: pct
      });
    }

    return progression;
  }
</script>

<div class="sidebar" style="position: relative;">
  <button 
    class="sidebar-toggle-btn"
    onclick={onToggleSidebar}
    aria-label="Toggle Sidebar"
  >
    {#if sidebarMinimized}
      ▶
    {:else}
      ◀
    {/if}
  </button>
  <div class="sidebar-header">
    <div class="sidebar-logo">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="24" height="24" style="margin-right: 0.15rem;">
        <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>
      </svg>
      Route<span>Log</span>
    </div>
    <div class="sidebar-logo-subtitle">Green Logistics & EV Fleet Dispatch</div>
  </div>

  <div class="sidebar-tabs">
    <button 
      class="tab-btn {activeTab === 'deliveries' ? 'active' : ''}" 
      onclick={() => activeTab = 'deliveries'}
    >
      {currentUser && currentUser.role === 'driver' ? 'My Parcels' : 'Parcels'} ({deliveries.length})
    </button>
    
    {#if currentUser && currentUser.role !== 'driver'}
      <button 
        class="tab-btn {activeTab === 'vehicles' ? 'active' : ''}" 
        onclick={() => activeTab = 'vehicles'}
      >
        Vehicles ({vehicles.length})
      </button>
    {/if}

    <button 
      class="tab-btn {activeTab === 'routes' ? 'active' : ''}" 
      onclick={() => activeTab = 'routes'}
    >
      {currentUser && currentUser.role === 'driver' ? 'My Route' : 'Routes'} ({routes.length})
    </button>

    {#if currentUser && currentUser.role === 'admin'}
      <button 
        class="tab-btn {activeTab === 'users' ? 'active' : ''}" 
        onclick={() => activeTab = 'users'}
      >
        Users ({usersList.length})
      </button>
    {/if}
  </div>

  <div class="sidebar-content">
    
    {#if activeTab === 'deliveries'}
      {#if currentUser && currentUser.role !== 'driver'}
        <div style="margin-bottom: 1.5rem; background: var(--bg-app); border: 1px solid var(--border-light); border-radius: 6px; padding: 1rem;">
          <h3 style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 0.75rem;">Add Parcel</h3>
          <form onsubmit={handleAddDelivery}>
            <div class="form-group">
              <label class="form-label" for="client">Recipient Name</label>
              <input id="client" class="form-input" type="text" placeholder="e.g. John Doe" bind:value={clientName} required />
            </div>
            <div class="form-group">
              <label class="form-label" for="address">Shipping Address</label>
              <input id="address" class="form-input" type="text" placeholder="e.g. Friedrichstraße 100, Berlin" bind:value={addressText} required />
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1.2fr; gap: 0.5rem; margin-bottom: 0.75rem;">
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="weight">Weight (kg)</label>
                <input id="weight" class="form-input" type="number" step="0.1" min="0.1" bind:value={deliveryWeight} required />
              </div>
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="lat">Coordinates (Lat, Lng)</label>
                <div style="display: flex; gap: 0.25rem;">
                  <input id="lat" class="form-input" type="number" step="0.000001" style="padding: 0.4rem;" bind:value={deliveryLat} required />
                  <input class="form-input" type="number" step="0.000001" style="padding: 0.4rem;" bind:value={deliveryLng} required />
                </div>
              </div>
            </div>
            <button type="submit" class="btn btn-secondary" style="padding: 0.5rem 1rem;">Add Parcel</button>
          </form>
        </div>
      {/if}

      <h3 style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-dark); margin-bottom: 0.5rem; font-weight: 700; letter-spacing: 0.05em;">Parcels List</h3>
      <div class="card-list">
        {#if deliveries.length === 0}
          <div style="text-align: center; padding: 1.5rem; color: var(--text-dark); font-size: 0.85rem;">
            {currentUser && currentUser.role === 'driver' ? 'No parcels assigned to you.' : 'No parcels added yet. Drop a pin on the map or use the form above.'}
          </div>
        {/if}
        {#each deliveries as delivery}
          <div class="item-card">
            <div class="item-card-header">
              <div>
                <span class="item-card-title">{delivery.customer_name}</span>
                <span class="item-card-badge badge-{delivery.status}" style="margin-left: 0.5rem;">
                  {delivery.status}
                </span>
              </div>

              {#if currentUser && currentUser.role !== 'driver'}
                <button 
                  class="delete-icon-btn" 
                  onclick={() => onDeleteDelivery(delivery.id)}
                  aria-label="Delete delivery stop"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="3 6 5 6 21 6"></polyline>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                  </svg>
                </button>
              {/if}
            </div>
            <div class="item-card-subtitle">{delivery.address}</div>
            <div class="item-card-meta">
              <span>Weight: {delivery.weight} kg</span>
              <span>Coord: {delivery.lat.toFixed(4)}, {delivery.lng.toFixed(4)}</span>
              {#if delivery.sequence_number}
                <span style="color: var(--primary); font-weight: 600;">Stop #{delivery.sequence_number}</span>
              {/if}
            </div>

            <!-- Route Assignment Dropdown (Admin/Operator only) -->
            {#if currentUser && currentUser.role !== 'driver'}
              <div style="margin-top: 0.65rem; border-top: 1px solid var(--border-light); padding-top: 0.5rem; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; width: 100%;">
                <label class="form-label" style="margin-bottom: 0; font-size: 0.72rem; color: var(--text-muted); font-weight: 600;" for="assign-vehicle-{delivery.id}">Route:</label>
                <select 
                  id="assign-vehicle-{delivery.id}"
                  class="form-input" 
                  style="height: 28px; padding: 2px 6px; font-size: 0.75rem; width: auto; min-width: 130px; margin-bottom: 0; background: var(--bg-app); border-color: var(--border-light); border-radius: 4px; color: var(--text-main); font-weight: 500;"
                  value={(() => {
                    if (!delivery.route_id) return '';
                    const route = routes.find(r => r.route.id === delivery.route_id);
                    return route ? route.vehicle.id : '';
                  })()}
                  onchange={(e) => {
                    const val = e.target.value;
                    onMoveDeliveryToVehicle(delivery.id, val ? parseInt(val) : null);
                  }}
                >
                  <option value="">Unassigned (Pending)</option>
                  {#each vehicles as vehicle}
                    <option value={vehicle.id}>{vehicle.name} Route</option>
                  {/each}
                </select>
              </div>
            {/if}

            <!-- Driver Action Controls -->
            {#if currentUser && currentUser.role === 'driver'}
              <div style="margin-top: 0.75rem; display: flex; gap: 0.35rem; border-top: 1px solid var(--border-light); padding-top: 0.5rem;">
                <button 
                  class="btn btn-secondary" 
                  style="padding: 0.35rem; font-size: 0.75rem; flex: 1; {delivery.status === 'delivered' ? 'background: rgba(16, 185, 129, 0.25); border-color: var(--success); color: var(--success)' : ''}"
                  onclick={() => onUpdateDeliveryStatus(delivery.id, 'delivered')}
                >
                  Delivered
                </button>
                <button 
                  class="btn btn-secondary" 
                  style="padding: 0.35rem; font-size: 0.75rem; flex: 1; {delivery.status === 'failed' ? 'background: rgba(239, 68, 68, 0.25); border-color: var(--danger); color: var(--danger)' : ''}"
                  onclick={() => onUpdateDeliveryStatus(delivery.id, 'failed')}
                >
                  Failed
                </button>
                <button 
                  class="btn btn-secondary" 
                  style="padding: 0.35rem; font-size: 0.75rem; flex: 0.8; {delivery.status === 'assigned' ? 'background: rgba(6, 182, 212, 0.25); border-color: var(--primary); color: var(--primary)' : ''}"
                  onclick={() => onUpdateDeliveryStatus(delivery.id, 'assigned')}
                >
                  Reset
                </button>
              </div>
            {/if}
          </div>
        {/each}
      </div>

    {:else if activeTab === 'vehicles'}
      {#if currentUser && currentUser.role !== 'driver'}
        <div style="margin-bottom: 1.5rem; background: var(--bg-app); border: 1px solid var(--border-light); border-radius: 6px; padding: 1rem;">
          <h3 style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 0.75rem;">Add Vehicle</h3>
          <form onsubmit={handleAddVehicle}>
            <div class="form-group">
              <label class="form-label" for="vehicle-name">Vehicle Name / Label</label>
              <input id="vehicle-name" class="form-input" type="text" placeholder="e.g. Cargo Bike D" bind:value={vehicleName} required />
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1.2fr; gap: 0.5rem; margin-bottom: 0.75rem;">
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="vehicle-capacity">Capacity Limit (kg)</label>
                <input id="vehicle-capacity" class="form-input" type="number" step="1" min="1" bind:value={vehicleCapacity} required />
              </div>
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="depot-lat">Depot Coordinates</label>
                <div style="display: flex; gap: 0.25rem;">
                  <input id="depot-lat" class="form-input" type="number" step="0.000001" style="padding: 0.4rem;" bind:value={depotLat} required />
                  <input class="form-input" type="number" step="0.000001" style="padding: 0.4rem;" bind:value={depotLng} required />
                </div>
              </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.5rem; margin-bottom: 0.75rem;">
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="vehicle-battery">Battery (kWh)</label>
                <input id="vehicle-battery" class="form-input" type="number" step="0.1" min="0.1" bind:value={vehicleBatteryCapacity} required />
              </div>
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="vehicle-charge">Charge (kWh)</label>
                <input id="vehicle-charge" class="form-input" type="number" step="0.1" min="0.1" max={vehicleBatteryCapacity} bind:value={vehicleCurrentBattery} required />
              </div>
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="vehicle-rate">Rate (kWh/km)</label>
                <input id="vehicle-rate" class="form-input" type="number" step="0.001" min="0.001" bind:value={vehicleConsumptionRate} required />
              </div>
            </div>
            <button type="submit" class="btn btn-secondary" style="padding: 0.5rem 1rem;">Add Vehicle</button>
          </form>
        </div>
      {/if}

      <h3 style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-dark); margin-bottom: 0.5rem; font-weight: 700; letter-spacing: 0.05em;">Active Fleet</h3>
      <div class="card-list">
        {#if vehicles.length === 0}
          <div style="text-align: center; padding: 1.5rem; color: var(--text-dark); font-size: 0.85rem;">
            No vehicles registered.
          </div>
        {/if}
        {#each vehicles as vehicle}
          <div class="item-card">
            <div class="item-card-header">
              <span class="item-card-title">{vehicle.name}</span>
              {#if currentUser && currentUser.role !== 'driver'}
                <button 
                  class="delete-icon-btn" 
                  onclick={() => onDeleteVehicle(vehicle.id)}
                  aria-label="Delete vehicle"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="3 6 5 6 21 6"></polyline>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                  </svg>
                </button>
              {/if}
            </div>
             <div class="item-card-meta" style="margin-top: 0.5rem; display: flex; flex-direction: column; gap: 0.25rem; width: 100%;">
              <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <span>Payload Cap: <strong>{vehicle.capacity} kg</strong></span>
                <span>Status: <span style="color: var(--success); font-weight: 600;">{vehicle.status}</span></span>
              </div>
              {#if vehicle.battery_capacity}
                <div style="display: flex; align-items: center; gap: 0.5rem; width: 100%; margin-top: 0.25rem;">
                  <div style="flex: 1; height: 6px; background: rgba(0,0,0,0.06); border-radius: 3px; overflow: hidden;">
                    <div style="width: {Math.min(100, Math.max(0, Math.round(parseFloat(vehicle.current_battery) / parseFloat(vehicle.battery_capacity) * 100)))}%; height: 100%; background: var(--primary); border-radius: 3px;"></div>
                  </div>
                  <span style="font-size: 0.7rem; color: var(--text-main); font-weight: 600; min-width: 65px; text-align: right;">
                    ⚡ {Math.round(parseFloat(vehicle.current_battery) / parseFloat(vehicle.battery_capacity) * 100)}% ({parseFloat(vehicle.current_battery).toFixed(1)} kWh)
                  </span>
                </div>
                <div style="font-size: 0.7rem; color: var(--text-dark); margin-top: 0.1rem;">
                  Consumption: {vehicle.consumption_rate} kWh/km
                </div>
              {/if}
            </div>
            <div class="item-card-subtitle" style="margin-top: 0.25rem; font-size: 0.7rem;">Depot: {parseFloat(vehicle.start_lat).toFixed(4)}, {parseFloat(vehicle.start_lng).toFixed(4)}</div>
            {#if currentUser && currentUser.role !== 'driver'}
              <button 
                class="btn btn-secondary" 
                style="margin-top: 0.65rem; padding: 4px 8px; font-size: 0.75rem; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 4px; border-color: rgba(16, 185, 129, 0.2); background: rgba(16, 185, 129, 0.02); color: var(--primary);"
                onclick={() => onOptimize(vehicle.id)}
                disabled={isOptimizing}
              >
                ⚡ Optimize Route
              </button>
            {/if}
          </div>
        {/each}
      </div>

    {:else if activeTab === 'routes'}
      {#if optimizationSummary}
        <h3 style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-dark); margin-bottom: 0.5rem; font-weight: 700; letter-spacing: 0.05em;">Dispatch Metrics</h3>
        
        <div style="background: rgba(6, 182, 212, 0.04); border: 1px dashed rgba(6, 182, 212, 0.25); border-radius: 8px; padding: 0.75rem; margin-bottom: 1rem; display: flex; flex-direction: column; gap: 0.35rem; font-size: 0.75rem;">
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="color: var(--text-muted); font-weight: 500;">Routing Engine:</span>
            <span style="color: var(--primary); font-weight: 700; background: rgba(6, 182, 212, 0.08); padding: 2px 6px; border-radius: 4px;">
              {optimizationSummary.routing_calculation || 'Unknown'}
            </span>
          </div>
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <span style="color: var(--text-muted); font-weight: 500;">Traffic Model:</span>
            <span style="color: var(--success); font-weight: 700; background: rgba(16, 185, 129, 0.08); padding: 2px 6px; border-radius: 4px;">
              {optimizationSummary.traffic_calculation || 'Unknown'}
            </span>
          </div>
        </div>

        <div class="stats-summary">
          <div class="stat-box">
            <div class="stat-lbl">Distance</div>
            <div class="stat-val">{formatDistance(optimizationSummary.total_distance)}</div>
          </div>
          <div class="stat-box">
            <div class="stat-lbl">Travel Time</div>
            <div class="stat-val">{formatDuration(optimizationSummary.total_duration)}</div>
          </div>
          {#if optimizationSummary.total_energy_consumed !== undefined}
            <div class="stat-box" style="border-color: rgba(16, 185, 129, 0.25); background: rgba(16, 185, 129, 0.02);">
              <div class="stat-lbl" style="color: var(--primary);">Energy Used</div>
              <div class="stat-val" style="color: var(--primary); font-weight: bold;">{parseFloat(optimizationSummary.total_energy_consumed).toFixed(1)} kWh</div>
            </div>
            <div class="stat-box" style="border-color: rgba(16, 185, 129, 0.25); background: rgba(16, 185, 129, 0.02);">
              <div class="stat-lbl" style="color: var(--primary);">CO2 Offset</div>
              <div class="stat-val" style="color: var(--primary); font-weight: bold;">{parseFloat(optimizationSummary.co2_saved).toFixed(1)} kg</div>
            </div>
          {/if}
          {#if currentUser && currentUser.role !== 'driver'}
            <div class="stat-box">
              <div class="stat-lbl">Active Fleet</div>
              <div class="stat-val">{optimizationSummary.vehicles_used} / {vehicles.length}</div>
            </div>
            <div class="stat-box" style="border-color: {optimizationSummary.unassigned_deliveries > 0 ? 'var(--warning)' : 'var(--border-light)'}">
              <div class="stat-lbl">Unassigned</div>
              <div class="stat-val" style="color: {optimizationSummary.unassigned_deliveries > 0 ? 'var(--warning)' : 'var(--success)'}">
                {optimizationSummary.unassigned_deliveries}
              </div>
            </div>
          {/if}
        </div>
      {/if}

      <h3 style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-dark); margin-bottom: 0.5rem; font-weight: 700; letter-spacing: 0.05em;">
        {currentUser && currentUser.role === 'driver' ? 'Your Assigned Path' : 'Optimized Paths'}
      </h3>
      <div class="card-list">
        {#if routes.length === 0}
          <div style="text-align: center; padding: 2rem; color: var(--text-dark); font-size: 0.85rem;">
            {currentUser && currentUser.role === 'driver' ? 'No route assigned to you yet.' : 'Routes have not been optimized yet.'}
          </div>
        {/if}
        {#each routes as routeData, idx}
          {@const routeColors = ['#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#ec4899', '#ff6b00']}
          {@const color = routeColors[idx % routeColors.length]}
          {@const batteryProg = calculateBatteryProgression(routeData)}
          <div class="item-card" style="border-left: 4px solid {color}; padding-left: 0.75rem;">
            <div class="item-card-header">
              <span class="item-card-title">{routeData.vehicle.name} Route</span>
              <span class="item-card-badge" style="background: {color}20; color: {color}; border: 1px solid {color}40">
                {routeData.deliveries.length} stops
              </span>
            </div>
            <div class="item-card-meta" style="margin-top: 0.25rem;">
              <span>Dist: {formatDistance(routeData.route.total_distance)}</span>
              <span>Time: {formatDuration(routeData.route.total_duration)}</span>
              {#if routeData.vehicle && routeData.vehicle.consumption_rate}
                {@const energy = (parseFloat(routeData.route.total_distance) / 1000) * parseFloat(routeData.vehicle.consumption_rate)}
                <span style="color: var(--primary); font-weight: 600;">⚡ {energy.toFixed(2)} kWh</span>
              {/if}
            </div>
            
            <div style="margin-top: 0.75rem; border-top: 1px solid var(--border-light); padding-top: 0.5rem;">
              <ol style="margin-left: 1.15rem; font-size: 0.75rem; color: var(--text-muted); display: flex; flex-direction: column; gap: 0.35rem;">
                {#each routeData.deliveries as delivery, sIdx}
                  {@const stopBattery = batteryProg[sIdx]}
                  <li>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; width: 100%;">
                      <div>
                        <strong style="color: var(--text-main);">{delivery.customer_name}</strong>
                        {#if delivery.status === 'delivered'}
                          <span style="color: var(--success); font-size: 0.65rem; margin-left: 0.35rem; font-weight: bold;">(Delivered)</span>
                        {:else if delivery.status === 'failed'}
                          <span style="color: var(--danger); font-size: 0.65rem; margin-left: 0.35rem; font-weight: bold;">(Failed)</span>
                        {/if}
                      </div>
                      <div style="display: flex; align-items: center; gap: 0.35rem;">
                        {#if currentUser && currentUser.role !== 'driver'}
                          <div style="display: inline-flex; align-items: center; gap: 0.2rem; background: rgba(0,0,0,0.03); border: 1px solid var(--border-light); border-radius: 4px; padding: 2px 4px; margin-right: 0.25rem;">
                            <button 
                              style="background: none; border: none; padding: 0 2px; color: var(--text-muted); cursor: pointer; font-size: 0.6rem; line-height: 1; font-weight: bold; opacity: {sIdx === 0 ? '0.25' : '1'};"
                              onclick={() => {
                                if (sIdx > 0) {
                                  const newIds = routeData.deliveries.map(d => d.id);
                                  const temp = newIds[sIdx];
                                  newIds[sIdx] = newIds[sIdx - 1];
                                  newIds[sIdx - 1] = temp;
                                  onReorderRouteStops(routeData.route.id, newIds);
                                }
                              }}
                              disabled={sIdx === 0}
                              aria-label="Move stop up"
                            >
                              ▲
                            </button>
                            <button 
                              style="background: none; border: none; padding: 0 2px; color: var(--text-muted); cursor: pointer; font-size: 0.6rem; line-height: 1; font-weight: bold; opacity: {sIdx === routeData.deliveries.length - 1 ? '0.25' : '1'};"
                              onclick={() => {
                                if (sIdx < routeData.deliveries.length - 1) {
                                  const newIds = routeData.deliveries.map(d => d.id);
                                  const temp = newIds[sIdx];
                                  newIds[sIdx] = newIds[sIdx + 1];
                                  newIds[sIdx + 1] = temp;
                                  onReorderRouteStops(routeData.route.id, newIds);
                                }
                              }}
                              disabled={sIdx === routeData.deliveries.length - 1}
                              aria-label="Move stop down"
                            >
                              ▼
                            </button>
                          </div>
                        {/if}
                        {#if stopBattery}
                          <span style="font-size: 0.68rem; font-weight: 600; color: {stopBattery.batteryPct < 20 ? 'var(--danger)' : 'var(--primary)'};">
                            ⚡ {stopBattery.batteryPct}% ({stopBattery.batteryKwh} kWh)
                          </span>
                        {/if}
                      </div>
                    </div>
                    <div style="font-size: 0.7rem; color: var(--text-dark);">{delivery.address} ({delivery.weight} kg)</div>
                  </li>
                {/each}
              </ol>
              {#if currentUser && currentUser.role !== 'driver'}
                <button 
                  class="btn btn-secondary" 
                  style="margin-top: 0.75rem; padding: 4px 8px; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; gap: 4px; border-color: rgba(6, 182, 212, 0.2); background: rgba(6, 182, 212, 0.02); color: var(--primary); width: auto;"
                  onclick={() => onOptimize(routeData.vehicle.id)}
                  disabled={isOptimizing}
                >
                  🔄 Re-optimize Route
                </button>
              {/if}
            </div>
          </div>
        {/each}
      </div>

    {:else if activeTab === 'users'}
      <!-- Admin Users Management Tab -->
      {#if currentUser && currentUser.role === 'admin'}
        <div style="margin-bottom: 1.5rem; background: var(--bg-app); border: 1px solid var(--border-light); border-radius: 6px; padding: 1rem;">
          <h3 style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 0.75rem;">Create User</h3>
          <form onsubmit={handleAddUser}>
            <div class="form-group">
              <label class="form-label" for="new-username">Username</label>
              <input id="new-username" class="form-input" type="text" placeholder="username" bind:value={newUsername} required />
            </div>
            <div class="form-group">
              <label class="form-label" for="new-password">Password</label>
              <input id="new-password" class="form-input" type="password" placeholder="password" bind:value={newPassword} required />
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.75rem;">
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="new-role">Role</label>
                <select id="new-role" class="form-input" style="height: 38px;" bind:value={newRole}>
                  <option value="driver">Driver</option>
                  <option value="operator">Operator</option>
                  <option value="admin">Admin</option>
                </select>
              </div>
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="new-vehicle-id">Assign Vehicle</label>
                <select id="new-vehicle-id" class="form-input" style="height: 38px;" bind:value={newVehicleId} disabled={newRole !== 'driver'}>
                  <option value="">None</option>
                  {#each vehicles as vehicle}
                    <option value={vehicle.id}>{vehicle.name}</option>
                  {/each}
                </select>
              </div>
            </div>
            <button type="submit" class="btn btn-secondary" style="padding: 0.5rem 1rem;">Create User</button>
          </form>
        </div>

        <h3 style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-dark); margin-bottom: 0.5rem; font-weight: 700; letter-spacing: 0.05em;">Registered Users</h3>
        <div class="card-list">
          {#each usersList as user}
            <div class="user-card">
              <div>
                <strong style="font-size: 0.9rem; color: var(--text-main);">@{user.username}</strong>
                <div style="margin-top: 0.15rem; display: flex; gap: 0.35rem; align-items: center;">
                  <span class="role-badge role-{user.role}" style="font-size: 0.6rem;">{user.role}</span>
                  {#if user.role === 'driver' && user.vehicle_id}
                    {@const v = vehicles.find(x => x.id === user.vehicle_id)}
                    <span style="font-size: 0.7rem; color: var(--text-dark);">
                      ({v ? v.name : `Vehicle ID: ${user.vehicle_id}`})
                    </span>
                  {/if}
                </div>
              </div>
              
              {#if currentUser.id !== user.id}
                <button 
                  class="delete-icon-btn" 
                  onclick={() => onDeleteUser(user.id)}
                  aria-label="Delete user"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="3 6 5 6 21 6"></polyline>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                  </svg>
                </button>
              {/if}
            </div>
          {/each}
        </div>
      {/if}
    {/if}

  </div>

  {#if currentUser && currentUser.role !== 'driver'}
    <div class="sidebar-footer">
      <button 
        class="btn btn-primary" 
        onclick={onOptimize} 
        disabled={isOptimizing || deliveries.length === 0}
      >
        {#if isOptimizing}
          <svg class="animate-spin" style="margin-right: 0.5rem;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" width="16" height="16">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" style="opacity: 0.25;"></circle>
            <path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" style="opacity: 0.75;"></path>
          </svg>
          Optimizing Routes...
        {:else}
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 0.25rem;">
            <polygon points="5 3 19 12 5 21 5 3"></polygon>
          </svg>
          Optimize Dispatch
        {/if}
      </button>
    </div>
  {/if}
</div>

<style>
  .animate-spin {
    animation: spin 1s linear infinite;
  }
  @keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }
</style>
