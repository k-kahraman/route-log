<script>
  import { onMount, onDestroy } from 'svelte';
  import L from 'leaflet';

  let { 
    currentUser = null, 
    vehicles = [], 
    deliveries = [], 
    routes = [], 
    sidebarMinimized = false,
    onAddDelivery,
    onUpdateDeliveryStatus
  } = $props();

  $effect(() => {
    if (map && sidebarMinimized !== undefined) {
      setTimeout(() => {
        map.invalidateSize();
      }, 320); // slightly longer than CSS transition time
    }
  });

  let mapContainer = $state();
  let map = $state();
  let markersLayer = $state();
  let routesLayer = $state();

  // Custom Context Menu State
  let contextMenuVisible = $state(false);
  let contextMenuX = $state(0);
  let contextMenuY = $state(0);
  let contextMenuLat = $state(0);
  let contextMenuLng = $state(0);

  // Search Bar State
  let searchQuery = $state('');
  let searchResults = $state([]);
  let searchActive = $state(false);
  let searchMarker = $state(null);

  const routeColors = ['#ff6b00', '#3b82f6', '#f59e0b', '#10b981', '#8b5cf6', '#ec4899'];

  onMount(() => {
    map = L.map(mapContainer, {
      zoomControl: false,
      doubleClickZoom: false
    }).setView([39.865400, 32.735000], 13);

    L.control.zoom({ position: 'bottomright' }).addTo(map);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    markersLayer = L.layerGroup().addTo(map);
    routesLayer = L.layerGroup().addTo(map);

    map.on('click', (e) => {
      contextMenuVisible = false;
      if (onAddDelivery && currentUser && currentUser.role !== 'driver') {
        onAddDelivery(e.latlng.lat, e.latlng.lng);
      }
    });

    map.on('dblclick', (e) => {
      contextMenuVisible = false;
      if (onAddDelivery && currentUser && currentUser.role !== 'driver') {
        onAddDelivery(e.latlng.lat, e.latlng.lng);
      }
    });

    map.on('contextmenu', (e) => {
      e.originalEvent.preventDefault();
      contextMenuLat = e.latlng.lat;
      contextMenuLng = e.latlng.lng;
      contextMenuX = e.originalEvent.clientX;
      contextMenuY = e.originalEvent.clientY;
      contextMenuVisible = true;
    });

    map.on('dragstart', () => {
      contextMenuVisible = false;
    });

    map.on('zoomstart', () => {
      contextMenuVisible = false;
    });

    // Expose map handler for leaflet popup action buttons
    window.handleMapStatusUpdate = (id, newStatus) => {
      if (onUpdateDeliveryStatus) {
        onUpdateDeliveryStatus(id, newStatus);
        map.closePopup();
      }
    };

    window.handleMapSearchAdd = (lat, lng) => {
      if (onAddDelivery) {
        onAddDelivery(lat, lng);
        if (searchMarker) {
          searchMarker.closePopup();
        }
      }
    };

    updateMap();
  });

  onDestroy(() => {
    if (map) {
      map.remove();
    }
    if (window.handleMapStatusUpdate) {
      delete window.handleMapStatusUpdate;
    }
    if (window.handleMapSearchAdd) {
      delete window.handleMapSearchAdd;
    }
  });

  async function handleSearch() {
    if (!searchQuery.trim()) return;
    try {
      const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(searchQuery)}&limit=5`, {
        headers: {
          'User-Agent': 'RouteLogEV-OS-Search/1.0'
        }
      });
      searchResults = await res.json();
      searchActive = true;
    } catch (err) {
      console.error('Search error:', err);
    }
  }

  function selectSearchResult(result) {
    const lat = parseFloat(result.lat);
    const lon = parseFloat(result.lon);
    map.setView([lat, lon], 15);
    searchActive = false;
    searchResults = [];

    if (searchMarker) {
      searchMarker.remove();
    }

    const searchIcon = L.divIcon({
      html: `
        <div style="
          background-color: #ef4444; 
          border: 2px solid #ffffff; 
          border-radius: 50%; 
          width: 28px; 
          height: 28px; 
          display: flex; 
          align-items: center; 
          justify-content: center;
          color: #ffffff;
          font-size: 13px;
          box-shadow: 0 4px 10px rgba(15, 23, 42, 0.25);
        ">
          📍
        </div>
      `,
      className: 'custom-search-marker',
      iconSize: [28, 28],
      iconAnchor: [14, 14],
      popupAnchor: [0, -14]
    });

    const popupHtml = `
      <div style="font-family: 'Inter', sans-serif; color: #0f172a; font-size: 12px; line-height: 1.4; min-width: 160px; padding: 0.25rem;">
        <strong style="color: #0f172a; font-size: 13px;">Found Location</strong><br>
        <span style="color: #475569; font-size: 11px;">${result.display_name}</span><br>
        ${currentUser && currentUser.role !== 'driver' ? `
          <button onclick="window.handleMapSearchAdd(${lat}, ${lon})" style="margin-top: 8px; width: 100%; background: var(--primary); border: none; color: #ffffff; padding: 6px 10px; border-radius: 4px; font-size: 10px; font-weight: bold; cursor: pointer; transition: all 0.15s ease;">📍 Add Stop Here</button>
        ` : ''}
      </div>
    `;

    searchMarker = L.marker([lat, lon], { icon: searchIcon })
      .addTo(map)
      .bindPopup(popupHtml)
      .openPopup();
  }

  const createDepotIcon = (name) => {
    return L.divIcon({
      html: `
        <div style="
          background-color: #ffffff; 
          border: 2px solid var(--primary); 
          border-radius: 50%; 
          width: 32px; 
          height: 32px; 
          display: flex; 
          align-items: center; 
          justify-content: center;
          box-shadow: 0 4px 10px rgba(15, 23, 42, 0.15);
        ">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="16" height="16">
            <path d="M3 21h18"></path>
            <path d="M3 7v1a3 3 0 0 0 6 0V7m0 1a3 3 0 0 0 6 0V7m0 1a3 3 0 0 0 6 0V7H3"></path>
            <path d="M4 21V10h16v11"></path>
          </svg>
        </div>
      `,
      className: 'custom-depot-marker',
      iconSize: [32, 32],
      iconAnchor: [16, 16],
      popupAnchor: [0, -16]
    });
  };

  const createDeliveryIcon = (status, seqNum) => {
    let bgColor = '#f97316'; // pending
    if (status === 'assigned') bgColor = '#3b82f6';
    else if (status === 'delivered') bgColor = '#10b981';
    else if (status === 'failed') bgColor = '#ef4444';

    const label = seqNum ? seqNum : '';
    
    return L.divIcon({
      html: `
        <div style="
          background-color: ${bgColor}; 
          border: 2px solid #ffffff; 
          border-radius: 50%; 
          width: 24px; 
          height: 24px; 
          display: flex; 
          align-items: center; 
          justify-content: center;
          color: #ffffff;
          font-size: 10px;
          font-weight: 700;
          box-shadow: 0 2px 6px rgba(15, 23, 42, 0.15);
        ">
          ${label}
        </div>
      `,
      className: 'custom-delivery-marker',
      iconSize: [24, 24],
      iconAnchor: [12, 12],
      popupAnchor: [0, -12]
    });
  };

  function updateMap() {
    if (!map || !markersLayer || !routesLayer) return;

    markersLayer.clearLayers();
    routesLayer.clearLayers();

    routes.forEach((routeData, idx) => {
      const geometryStr = routeData.route.geometry;
      if (geometryStr) {
        try {
          const geoJsonGeometry = JSON.parse(geometryStr);
          const color = routeColors[idx % routeColors.length];
          
          L.geoJSON(geoJsonGeometry, {
            style: {
              color: color,
              weight: 4,
              opacity: 0.8,
              dashArray: routeData.vehicle.name.includes('E-Bike') ? '5, 8' : undefined
            }
          }).addTo(routesLayer);
        } catch (e) {
          console.error(e);
        }
      }
    });

    vehicles.forEach((vehicle) => {
      let popupText = `<strong>Hub: ${vehicle.name}</strong><br>Payload Limit: ${vehicle.capacity} kg`;
      if (vehicle.battery_capacity) {
        const pct = Math.round(parseFloat(vehicle.current_battery) / parseFloat(vehicle.battery_capacity) * 100);
        popupText += `<br>⚡ Battery: ${pct}% (${parseFloat(vehicle.current_battery).toFixed(1)} / ${parseFloat(vehicle.battery_capacity).toFixed(1)} kWh)<br>Consumption: ${vehicle.consumption_rate} kWh/km`;
      }
      L.marker([vehicle.start_lat, vehicle.start_lng], {
        icon: createDepotIcon(vehicle.name)
      })
      .bindPopup(popupText)
      .addTo(markersLayer);
    });

    deliveries.forEach((delivery) => {
      let popupHtml = `
        <div style="font-family: 'Inter', sans-serif; color: #0f172a; font-size: 12px; line-height: 1.4;">
          <strong style="color: #0f172a; font-size: 13px;">${delivery.customer_name}</strong><br>
          <span style="color: #475569;">Address:</span> ${delivery.address}<br>
          <span style="color: #475569;">Weight:</span> ${delivery.weight} kg<br>
          <span style="color: #475569;">Status:</span> 
          <span style="text-transform: uppercase; font-weight: bold; color: ${
            delivery.status === 'delivered' ? '#10b981' : 
            delivery.status === 'failed' ? '#ef4444' : 
            delivery.status === 'assigned' ? '#3b82f6' : '#ff6b00'
          }">${delivery.status}</span>
          ${delivery.sequence_number ? `<br><strong style="color: var(--primary);">Stop Sequence: #${delivery.sequence_number}</strong>` : ''}
        </div>
      `;

      if (currentUser && currentUser.role === 'driver') {
        popupHtml += `
          <div style="margin-top: 8px; border-top: 1px solid rgba(15, 23, 42, 0.08); padding-top: 8px; display: flex; gap: 4px;">
            <button onclick="window.handleMapStatusUpdate(${delivery.id}, 'delivered')" style="flex: 1; background: rgba(16, 185, 129, 0.08); border: 1px solid #10b981; color: #10b981; padding: 4px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; cursor: pointer; transition: all 0.15s ease;">Delivered</button>
            <button onclick="window.handleMapStatusUpdate(${delivery.id}, 'failed')" style="flex: 1; background: rgba(239, 68, 68, 0.08); border: 1px solid #ef4444; color: #ef4444; padding: 4px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; cursor: pointer; transition: all 0.15s ease;">Failed</button>
          </div>
        `;
      }

      L.marker([delivery.lat, delivery.lng], {
        icon: createDeliveryIcon(delivery.status, delivery.sequence_number)
      })
      .bindPopup(popupHtml, {
        className: 'parcel-popup'
      })
      .addTo(markersLayer);
    });
  }

  $effect(() => {
    const v = vehicles;
    const d = deliveries;
    const r = routes;
    updateMap();
  });
</script>

<div class="map-container">
  {#if deliveries.length === 0}
    <div class="map-banner">
      {#if currentUser && currentUser.role === 'driver'}
        No deliveries assigned to you.
      {:else}
        <span></span> Double-click or right-click to add delivery stops.
      {/if}
    </div>
  {/if}
  <div class="leaflet-map" style="height: 100%; width: 100%;" bind:this={mapContainer}></div>

  <!-- Map Search Box -->
  <div class="map-search-bar">
    <form onsubmit={(e) => { e.preventDefault(); handleSearch(); }} style="display: flex; gap: 4px; background: #ffffff; border: 1px solid rgba(15, 23, 42, 0.08); border-radius: 6px; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08); padding: 0.25rem;">
      <input 
        type="text" 
        placeholder="Search location (e.g. Incek, Ankara)..." 
        bind:value={searchQuery}
        style="flex: 1; border: none; padding: 0.4rem 0.6rem; font-size: 0.75rem; font-family: 'Inter', sans-serif; outline: none; border-radius: 4px;"
      />
      <button 
        type="submit" 
        style="background: var(--primary); border: none; color: #ffffff; padding: 0.4rem 0.8rem; font-size: 0.75rem; font-weight: 600; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center;"
      >
        Search
      </button>
    </form>
    
    {#if searchActive && searchResults.length > 0}
      <div class="search-results-dropdown">
        {#each searchResults as result}
          <button 
            class="search-result-btn"
            onclick={() => selectSearchResult(result)}
          >
            {result.display_name}
          </button>
        {/each}
      </div>
    {:else if searchActive && searchResults.length === 0}
      <div style="background: #ffffff; border: 1px solid rgba(15, 23, 42, 0.08); border-radius: 6px; box-shadow: 0 10px 25px rgba(15, 23, 42, 0.12); margin-top: 4px; padding: 0.75rem; font-size: 0.7rem; color: var(--text-muted); text-align: center;">
        No locations found.
      </div>
    {/if}
  </div>

  {#if contextMenuVisible}
    <div class="custom-context-menu" style="position: fixed; left: {contextMenuX}px; top: {contextMenuY}px; z-index: 10000; background: #ffffff; border: 1px solid rgba(15, 23, 42, 0.08); border-radius: 8px; box-shadow: 0 10px 25px rgba(15, 23, 42, 0.12); padding: 0.35rem; display: flex; flex-direction: column; gap: 2px; min-width: 160px; font-family: 'Inter', sans-serif;">
      {#if currentUser && currentUser.role !== 'driver'}
        <button 
          class="context-menu-btn"
          onclick={() => {
            if (onAddDelivery) onAddDelivery(contextMenuLat, contextMenuLng);
            contextMenuVisible = false;
          }}
        >
          📍 Add Parcel Here
        </button>
      {/if}
      <button 
        class="context-menu-btn"
        onclick={() => {
          map.panTo([contextMenuLat, contextMenuLng]);
          contextMenuVisible = false;
        }}
      >
        🔍 Center Map Here
      </button>
      <div style="height: 1px; background: rgba(15, 23, 42, 0.08); margin: 0.25rem 0;"></div>
      <button 
        class="context-menu-btn"
        onclick={() => {
          map.zoomIn();
          contextMenuVisible = false;
        }}
      >
        ➕ Zoom In
      </button>
      <button 
        class="context-menu-btn"
        onclick={() => {
          map.zoomOut();
          contextMenuVisible = false;
        }}
      >
        ➖ Zoom Out
      </button>
    </div>
  {/if}
</div>

<style>
  .map-search-bar {
    position: absolute;
    top: 10px;
    left: 10px;
    z-index: 1000;
    width: 320px;
    display: flex;
    flex-direction: column;
  }
  .search-results-dropdown {
    background: #ffffff;
    border: 1px solid rgba(15, 23, 42, 0.08);
    border-radius: 6px;
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.12);
    margin-top: 4px;
    display: flex;
    flex-direction: column;
    max-height: 220px;
    overflow-y: auto;
    padding: 0.25rem;
    z-index: 1001;
  }
  .search-result-btn {
    background: none;
    border: none;
    text-align: left;
    padding: 0.5rem 0.75rem;
    font-size: 0.7rem;
    color: var(--text-main);
    font-family: 'Inter', sans-serif;
    cursor: pointer;
    border-radius: 4px;
    transition: background 0.15s ease;
    width: 100%;
  }
  .search-result-btn:hover {
    background: rgba(6, 182, 212, 0.08);
  }
  .context-menu-btn {
    background: none;
    border: none;
    text-align: left;
    padding: 0.5rem 0.75rem;
    font-size: 0.75rem;
    color: #0f172a;
    font-weight: 600;
    cursor: pointer;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: background 0.15s ease;
    width: 100%;
  }
  .context-menu-btn:hover {
    background: rgba(6, 182, 212, 0.08);
  }
  :global(.parcel-popup .leaflet-popup-content-wrapper) {
    background: #ffffff !important;
    border: 1px solid var(--border-light) !important;
    border-radius: 8px !important;
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08) !important;
  }
  :global(.parcel-popup .leaflet-popup-tip) {
    background: #ffffff !important;
    border-left: 1px solid var(--border-light) !important;
    border-bottom: 1px solid var(--border-light) !important;
  }
  :global(.parcel-popup .leaflet-popup-close-button) {
    color: var(--text-muted) !important;
  }
</style>
