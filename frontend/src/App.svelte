<script>
  import { onMount } from 'svelte';
  import Map from './components/Map.svelte';
  import Sidebar from './components/Sidebar.svelte';

  const API_BASE = 'http://localhost:8000/api';

  // Session State
  let token = $state(localStorage.getItem('auth_token') || '');
  let currentUser = $state(null);

  // App State
  let vehicles = $state([]);
  let deliveries = $state([]);
  let routes = $state([]);
  let usersList = $state([]);
  let isOptimizing = $state(false);
  let optimizationSummary = $state(null);
  let sidebarRef = $state();
  let sidebarMinimized = $state(false);

  function toggleSidebar() {
    sidebarMinimized = !sidebarMinimized;
  }

  // Login Form State
  let loginUsername = $state('');
  let loginPassword = $state('');
  let loginError = $state('');
  let isLoggingIn = $state(false);

  // Auto-authenticate on mount
  onMount(async () => {
    if (token) {
      const authenticated = await fetchCurrentUser();
      if (authenticated) {
        await refreshData();
      }
    }
  });

  // Secure API fetch helper
  async function apiFetch(path, options = {}) {
    const headers = {
      ...(options.headers || {}),
      'Content-Type': 'application/json'
    };

    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    try {
      const res = await fetch(`${API_BASE}${path}`, {
        ...options,
        headers
      });

      if (res.status === 401) {
        handleLogout();
        throw new Error('Session expired. Please log in again.');
      }

      return res;
    } catch (err) {
      console.error('Fetch error:', err);
      throw err;
    }
  }

  async function fetchCurrentUser() {
    try {
      const res = await apiFetch('/me');
      if (res.ok) {
        currentUser = await res.json();
        return true;
      }
    } catch (e) {
      console.error('Failed to authenticate:', e);
    }
    handleLogout();
    return false;
  }

  async function handleLogin(e) {
    if (e) e.preventDefault();
    if (!loginUsername.trim() || !loginPassword.trim()) {
      loginError = 'Please fill out all fields.';
      return;
    }

    isLoggingIn = true;
    loginError = '';

    try {
      const res = await fetch(`${API_BASE}/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: loginUsername, password: loginPassword })
      });

      const data = await res.json();
      if (res.ok) {
        token = data.token;
        currentUser = data.user;
        localStorage.setItem('auth_token', token);
        await refreshData();
      } else {
        loginError = data.error || 'Invalid credentials.';
      }
    } catch (err) {
      console.error(err);
      loginError = 'Network error. Please check backend server.';
    } finally {
      isLoggingIn = false;
    }
  }

  async function handleLogout() {
    if (token) {
      try {
        await fetch(`${API_BASE}/logout`, {
          method: 'POST',
          headers: { 'Authorization': `Bearer ${token}` }
        });
      } catch (e) {
        console.error(e);
      }
    }

    token = '';
    currentUser = null;
    localStorage.removeItem('auth_token');
    vehicles = [];
    deliveries = [];
    routes = [];
    usersList = [];
    optimizationSummary = null;
    loginUsername = '';
    loginPassword = '';
    loginError = '';
  }

  function handleQuickLogin(username, password) {
    loginUsername = username;
    loginPassword = password;
    handleLogin();
  }

  async function refreshData() {
    if (!token) return;
    
    const fetchPromises = [
      fetchVehicles(),
      fetchDeliveries(),
      fetchRoutes()
    ];

    if (currentUser && currentUser.role === 'admin') {
      fetchPromises.push(fetchUsers());
    }

    await Promise.all(fetchPromises);
    calculateSummaryFromRoutes();
  }

  async function fetchVehicles() {
    try {
      const res = await apiFetch('/vehicles');
      if (res.ok) {
        vehicles = await res.json();
      }
    } catch (e) {
      console.error(e);
    }
  }

  async function fetchDeliveries() {
    try {
      const res = await apiFetch('/deliveries');
      if (res.ok) {
        deliveries = await res.json();
      }
    } catch (e) {
      console.error(e);
    }
  }

  async function fetchRoutes() {
    try {
      const res = await apiFetch('/routes');
      if (res.ok) {
        routes = await res.json();
      }
    } catch (e) {
      console.error(e);
    }
  }

  async function fetchUsers() {
    try {
      const res = await apiFetch('/users');
      if (res.ok) {
        usersList = await res.json();
      }
    } catch (e) {
      console.error(e);
    }
  }

  function calculateSummaryFromRoutes() {
    if (routes.length === 0) {
      optimizationSummary = null;
      return;
    }

    let totalDist = 0;
    let totalDur = 0;
    let totalEnergy = 0;
    let totalCo2 = 0;
    routes.forEach(r => {
      const dist = parseFloat(r.route.total_distance);
      totalDist += dist;
      totalDur += parseFloat(r.route.total_duration);
      if (r.vehicle && r.vehicle.consumption_rate) {
        const rate = parseFloat(r.vehicle.consumption_rate);
        totalEnergy += (dist / 1000) * rate;
      }
      totalCo2 += (dist / 1000) * 0.12;
    });

    const unassigned = deliveries.filter(d => d.status === 'pending').length;
    const firstRoute = routes[0];
    const routingSource = firstRoute?.route?.routing_source || 'Unknown';
    const trafficSource = firstRoute?.route?.traffic_source || 'Unknown';

    optimizationSummary = {
      total_distance: totalDist,
      total_duration: totalDur,
      vehicles_used: routes.length,
      unassigned_deliveries: unassigned,
      total_energy_consumed: totalEnergy,
      co2_saved: totalCo2,
      routing_calculation: routingSource,
      traffic_calculation: trafficSource
    };
  }

  async function handleAddVehicle(vehicleData) {
    try {
      const res = await apiFetch('/vehicles', {
        method: 'POST',
        body: JSON.stringify(vehicleData)
      });
      if (res.ok) {
        await fetchVehicles();
      } else {
        const error = await res.json();
        alert('Error: ' + error.error);
      }
    } catch (e) {
      console.error(e);
    }
  }

  async function handleDeleteVehicle(id) {
    try {
      const res = await apiFetch(`/vehicles/${id}`, {
        method: 'DELETE'
      });
      if (res.ok) {
        await refreshData();
      }
    } catch (e) {
      console.error(e);
    }
  }

  async function handleAddDelivery(deliveryData) {
    try {
      const res = await apiFetch('/deliveries', {
        method: 'POST',
        body: JSON.stringify(deliveryData)
      });
      if (res.ok) {
        await fetchDeliveries();
      } else {
        const error = await res.json();
        alert('Error: ' + error.error);
      }
    } catch (e) {
      console.error(e);
    }
  }

  async function handleDeleteDelivery(id) {
    try {
      const res = await apiFetch(`/deliveries/${id}`, {
        method: 'DELETE'
      });
      if (res.ok) {
        await refreshData();
      }
    } catch (e) {
      console.error(e);
    }
  }

  async function handleUpdateDeliveryStatus(id, newStatus) {
    try {
      const res = await apiFetch(`/deliveries/${id}/status`, {
        method: 'PATCH',
        body: JSON.stringify({ status: newStatus })
      });
      if (res.ok) {
        await refreshData();
      } else {
        const error = await res.json();
        alert('Failed to update status: ' + error.error);
      }
    } catch (e) {
      console.error(e);
    }
  }

  async function handleMoveDeliveryToVehicle(deliveryId, vehicleId) {
    try {
      const res = await apiFetch(`/deliveries/${deliveryId}/route`, {
        method: 'PATCH',
        body: JSON.stringify({ vehicle_id: vehicleId })
      });
      if (res.ok) {
        await refreshData();
      } else {
        const error = await res.json();
        alert('Failed to reassign delivery: ' + error.error);
      }
    } catch (e) {
      console.error(e);
    }
  }

  async function handleReorderRouteStops(routeId, deliveryIds) {
    try {
      const res = await apiFetch(`/routes/${routeId}/sequence`, {
        method: 'PATCH',
        body: JSON.stringify({ delivery_ids: deliveryIds })
      });
      if (res.ok) {
        await refreshData();
      } else {
        const error = await res.json();
        alert('Failed to reorder route: ' + error.error);
      }
    } catch (e) {
      console.error(e);
    }
  }

  async function handleAddUser(userData) {
    try {
      const res = await apiFetch('/users', {
        method: 'POST',
        body: JSON.stringify(userData)
      });
      if (res.ok) {
        await fetchUsers();
      } else {
        const error = await res.json();
        alert('Error: ' + error.error);
      }
    } catch (e) {
      console.error(e);
    }
  }

  async function handleDeleteUser(id) {
    try {
      const res = await apiFetch(`/users/${id}`, {
        method: 'DELETE'
      });
      if (res.ok) {
        await fetchUsers();
      }
    } catch (e) {
      console.error(e);
    }
  }

  async function handleOptimize(vehicleId = null) {
    if (deliveries.length === 0) return;
    isOptimizing = true;
    try {
      const payload = {};
      if (vehicleId !== null) {
        payload.vehicle_id = vehicleId;
      }
      const res = await apiFetch('/optimize', {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (res.ok && data.success) {
        await refreshData();
      } else {
        alert('Optimization failed: ' + (data.message || data.error));
      }
    } catch (e) {
      console.error(e);
      alert('Network error trying to run optimization solver.');
    } finally {
      isOptimizing = false;
    }
  }

  function handleMapClickToAdd(lat, lng) {
    if (sidebarRef && currentUser && currentUser.role !== 'driver') {
      sidebarRef.prefillDeliveryCoords(lat, lng);
    }
  }
</script>

{#if !token || !currentUser}
  <div class="login-container">
    <div class="login-card">
      <div class="login-title">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="32" height="32" style="margin-right: 0.25rem;">
          <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>
        </svg>
        Route<span>Log</span>
      </div>
      <div class="login-subtitle">Green Logistics & EV Fleet Dispatch</div>

      {#if loginError}
        <div style="background: rgba(239,68,68,0.15); border: 1px solid var(--danger); border-radius: 6px; padding: 0.75rem 1rem; color: var(--danger); font-size: 0.85rem; margin-bottom: 1.25rem;">
          {loginError}
        </div>
      {/if}

      <form onsubmit={handleLogin}>
        <div class="form-group">
          <label class="form-label" for="username">Username</label>
          <input id="username" class="form-input" type="text" bind:value={loginUsername} placeholder="Enter your username" required />
        </div>
        <div class="form-group" style="margin-bottom: 1.75rem;">
          <label class="form-label" for="password">Password</label>
          <input id="password" class="form-input" type="password" bind:value={loginPassword} placeholder="••••••••" required />
        </div>
        
        <button type="submit" class="btn btn-primary" disabled={isLoggingIn}>
          {#if isLoggingIn}
            Signing In...
          {:else}
            Sign In
          {/if}
        </button>
      </form>

      <div class="demo-presets">
        <div class="demo-title">Demo Presets</div>
        <div class="demo-grid">
          <button class="demo-btn" onclick={() => handleQuickLogin('admin', 'admin123')}>
            <span class="demo-btn-role">Administrator</span>
            <span>admin / admin123</span>
          </button>
          <button class="demo-btn" onclick={() => handleQuickLogin('operator', 'operator123')}>
            <span class="demo-btn-role">Operator</span>
            <span>operator / operator123</span>
          </button>
          <button class="demo-btn" onclick={() => handleQuickLogin('driver_a', 'driver123')}>
            <span class="demo-btn-role">Driver A (Van A)</span>
            <span>driver_a / driver123</span>
          </button>
          <button class="demo-btn" onclick={() => handleQuickLogin('driver_b', 'driver123')}>
            <span class="demo-btn-role">Driver B (Van B)</span>
            <span>driver_b / driver123</span>
          </button>
        </div>
      </div>
    </div>
  </div>
{:else}
  <div class="app-container {sidebarMinimized ? 'minimized' : ''}">
    <div class="user-profile-header">
      <div class="user-profile-info">
        <div class="user-avatar">{currentUser.username[0].toUpperCase()}</div>
        <div class="user-details">
          <span class="user-name">@{currentUser.username}</span>
          <span class="role-badge role-{currentUser.role}">{currentUser.role}</span>
        </div>
      </div>
      <button class="logout-btn-nav" onclick={handleLogout}>Logout</button>
    </div>

    <Sidebar
      bind:this={sidebarRef}
      {currentUser}
      {vehicles}
      {deliveries}
      {routes}
      {usersList}
      {isOptimizing}
      {optimizationSummary}
      {sidebarMinimized}
      onAddVehicle={handleAddVehicle}
      onDeleteVehicle={handleDeleteVehicle}
      onAddDelivery={handleAddDelivery}
      onDeleteDelivery={handleDeleteDelivery}
      onUpdateDeliveryStatus={handleUpdateDeliveryStatus}
      onMoveDeliveryToVehicle={handleMoveDeliveryToVehicle}
      onReorderRouteStops={handleReorderRouteStops}
      onAddUser={handleAddUser}
      onDeleteUser={handleDeleteUser}
      onOptimize={handleOptimize}
      onToggleSidebar={toggleSidebar}
    />

    <Map
      {currentUser}
      {vehicles}
      {deliveries}
      {routes}
      {sidebarMinimized}
      onAddDelivery={handleMapClickToAdd}
      onUpdateDeliveryStatus={handleUpdateDeliveryStatus}
    />
  </div>
{/if}
