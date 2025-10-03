document.addEventListener('DOMContentLoaded', () => {
    // Initialize Bootstrap tooltips
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    [...tooltipTriggerList].forEach(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

    // Auto-dismiss compare alert after 3 seconds
    const compareAlert = document.getElementById('compareAlert');
    if (compareAlert) {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(compareAlert);
            bsAlert.close();
        }, 3000);
    }

    // Elements
    const searchInput = document.getElementById('turfSearch');
    const clearSearchBtn = document.getElementById('clearSearch');
    const featuredFilter = document.getElementById('featuredFilter');
    const priceRangeSelect = document.getElementById('priceRange');
    const capacityFilter = document.getElementById('capacityFilter');
    const facilityFilter = document.getElementById('facilityFilter');
    const sortBySelect = document.getElementById('sortBy');
    const resetFiltersBtn = document.getElementById('resetFilters');
    const turfContainer = document.getElementById('turfContainer');
    const noResults = document.getElementById('noResults');
    const loadingSpinner = document.getElementById('loadingSpinner');
    const findNearbyBtn = document.getElementById('findNearby');

    // Filter State
    const filterState = {
        searchQuery: '',
        showOnlyFeatured: false,
        priceRange: '',
        capacityRange: '',
        facility: '',
        sortBy: 'default',
        userLat: null,
        userLon: null,
        distanceSort: false
    };

    // OpenWeatherMap API Key
    const apiKey = '5e1007df769f52af693bbf713a979ec7';
    const weatherCache = new Map();
    const locationCache = new Map();
    const CACHE_DURATION = 30 * 60 * 1000; // Cache for 30 minutes

    // Function to normalize capacity values
    function normalizeCapacity(capacityStr) {
        if (!capacityStr || capacityStr.toLowerCase() === 'n/a') {
            return 0;
        }
        // Extract first number from strings like "15", "15 people", "10-20"
        const match = capacityStr.match(/^\d+/);
        return match ? parseInt(match[0]) : 0; // Return 0 for invalid formats
    }

    // Fetch weather and coordinates for each turf card
    document.querySelectorAll('.weather-info').forEach(weatherElement => {
        const city = weatherElement.getAttribute('data-city');
        fetchWeather(city, weatherElement);
        fetchTurfCoordinates(weatherElement.closest('.turf-item'));
    });

    // Function to fetch weather data for a given city
    async function fetchWeather(city, weatherElement) {
        const address = weatherElement.getAttribute('data-address');
        
        if (weatherCache.has(address)) {
            const cachedData = weatherCache.get(address);
            if (Date.now() - cachedData.timestamp < CACHE_DURATION) {
                updateWeatherDisplay(weatherElement, cachedData.data);
                return;
            }
        }

        try {
            let searchCity = city.trim();
            if (!searchCity.toLowerCase().includes('india')) {
                searchCity = `${searchCity}, India`;
            }

            const geocodeUrl = `https://api.openweathermap.org/geo/1.0/direct?q=${encodeURIComponent(searchCity)}&limit=1&appid=${apiKey}`;
            const geocodeResponse = await fetch(geocodeUrl);
            const geocodeData = await geocodeResponse.json();

            if (!geocodeResponse.ok) {
                throw new Error(`Geocoding failed: ${geocodeData.message || geocodeResponse.statusText}`);
            }

            if (!geocodeData || geocodeData.length === 0) {
                throw new Error('Location not found');
            }

            const { lat, lon } = geocodeData[0];

            const weatherUrl = `https://api.openweathermap.org/data/2.5/weather?lat=${lat}&lon=${lon}&units=metric&appid=${apiKey}`;
            const weatherResponse = await fetch(weatherUrl);
            const weatherData = await weatherResponse.json();

            if (!weatherResponse.ok) {
                throw new Error(`Weather fetch failed: ${weatherData.message || weatherResponse.statusText}`);
            }

            weatherCache.set(address, {
                timestamp: Date.now(),
                data: weatherData
            });

            updateWeatherDisplay(weatherElement, weatherData);
        } catch (error) {
            console.error(`Error fetching weather for ${city}:`, error.message);
            weatherElement.querySelector('.weather-text').textContent = 'Weather unavailable';
            const tooltip = bootstrap.Tooltip.getInstance(weatherElement);
            tooltip.setContent({ '.tooltip-inner': error.message || 'Unable to fetch weather data' });
        }
    }

    // Function to update the weather display on the turf card
    function updateWeatherDisplay(weatherElement, weatherData) {
        const weatherIcon = weatherElement.querySelector('.weather-icon');
        const weatherText = weatherElement.querySelector('.weather-text');
        const tooltip = bootstrap.Tooltip.getInstance(weatherElement);

        const iconCode = weatherData.weather[0].icon;
        const description = weatherData.weather[0].description;
        const temp = Math.round(weatherData.main.temp);
        const humidity = weatherData.main.humidity;
        const windSpeed = weatherData.wind.speed;

        weatherIcon.src = `https://openweathermap.org/img/wn/${iconCode}.png`;
        weatherText.textContent = `${description.charAt(0).toUpperCase() + description.slice(1)}, ${temp}°C`;

        tooltip.setContent({
            '.tooltip-inner': `Weather: ${description.charAt(0).toUpperCase() + description.slice(1)}\nTemperature: ${temp}°C\nHumidity: ${humidity}%\nWind Speed: ${windSpeed} m/s`
        });
    }

    // Function to fetch coordinates for a turf
    async function fetchTurfCoordinates(turfItem) {
        const city = turfItem.querySelector('.weather-info').getAttribute('data-city');
        const cacheKey = city.trim();

        if (locationCache.has(cacheKey)) {
            const cachedData = locationCache.get(cacheKey);
            if (Date.now() - cachedData.timestamp < CACHE_DURATION) {
                turfItem.setAttribute('data-lat', cachedData.lat);
                turfItem.setAttribute('data-lon', cachedData.lon);
                return;
            }
        }

        try {
            let searchCity = city.trim();
            if (!searchCity.toLowerCase().includes('india')) {
                searchCity = `${searchCity}, India`;
            }

            const geocodeUrl = `https://api.openweathermap.org/geo/1.0/direct?q=${encodeURIComponent(searchCity)}&limit=1&appid=${apiKey}`;
            const geocodeResponse = await fetch(geocodeUrl);
            const geocodeData = await geocodeResponse.json();

            if (!geocodeResponse.ok) {
                throw new Error(`Geocoding failed: ${geocodeData.message || geocodeResponse.statusText}`);
            }

            if (!geocodeData || geocodeData.length === 0) {
                throw new Error('Location not found');
            }

            const { lat, lon } = geocodeData[0];
            turfItem.setAttribute('data-lat', lat);
            turfItem.setAttribute('data-lon', lon);

            locationCache.set(cacheKey, {
                timestamp: Date.now(),
                lat: lat,
                lon: lon
            });
        } catch (error) {
            console.error(`Error fetching coordinates for ${city}:`, error.message);
            turfItem.setAttribute('data-lat', '');
            turfItem.setAttribute('data-lon', '');
        }
    }

    // Haversine formula to calculate distance between two coordinates (in kilometers)
    function calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 6371; // Earth's radius in kilometers
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                  Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                  Math.sin(dLon / 2) * Math.sin(dLon / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    }

    // Debounce utility function
    function debounce(func, wait) {
        let timeout;
        return function (...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }

    // Filter and sort turfs
    function applyFiltersAndSort() {
        loadingSpinner.classList.remove('d-none');
        setTimeout(() => {
            const items = Array.from(turfContainer.querySelectorAll('.turf-item'));
            let visibleItems = 0;

            // Filter
            items.forEach(item => {
                const name = item.getAttribute('data-name').toLowerCase();
                const address = item.getAttribute('data-address').toLowerCase();
                const facility = item.getAttribute('data-facility').toLowerCase();
                const isFeatured = item.getAttribute('data-is-featured') === 'true';
                const price = parseFloat(item.getAttribute('data-price'));
                const rawCapacity = item.getAttribute('data-capacity');
                const capacity = normalizeCapacity(rawCapacity);

                // Debug invalid capacity values
                if (rawCapacity && isNaN(capacity)) {
                    console.warn(`Invalid capacity for turf "${name}": "${rawCapacity}"`);
                }

                const matchesSearch = filterState.searchQuery === '' || 
                    name.includes(filterState.searchQuery) || 
                    address.includes(filterState.searchQuery) || 
                    facility.includes(filterState.searchQuery);
                const matchesFeatured = !filterState.showOnlyFeatured || isFeatured;

                let matchesPrice = true;
                if (filterState.priceRange) {
                    const [min, max] = filterState.priceRange.split('-').map(val => val === '+' ? Infinity : parseInt(val));
                    matchesPrice = price >= min && (max === Infinity ? true : price <= max);
                }

                let matchesCapacity = true;
                if (filterState.capacityRange) {
                    const [min, max] = filterState.capacityRange.split('-').map(val => val === '+' ? Infinity : parseInt(val));
                    matchesCapacity = capacity >= min && (max === Infinity ? true : capacity <= max);
                }

                const matchesFacility = filterState.facility === '' || facility.includes(filterState.facility.toLowerCase());

                const isVisible = matchesSearch && matchesFeatured && matchesPrice && matchesCapacity && matchesFacility;
                item.classList.toggle('d-none', !isVisible);
                if (isVisible) visibleItems++;
            });

            // Sort
            const sortedItems = Array.from(items).sort((a, b) => {
                const priceA = parseFloat(a.getAttribute('data-price'));
                const priceB = parseFloat(b.getAttribute('data-price'));
                const capacityA = normalizeCapacity(a.getAttribute('data-capacity'));
                const capacityB = normalizeCapacity(b.getAttribute('data-capacity'));
                const nameA = a.getAttribute('data-name').toLowerCase();
                const nameB = b.getAttribute('data-name').toLowerCase();
                const distA = parseFloat(a.getAttribute('data-distance')) || Infinity;
                const distB = parseFloat(b.getAttribute('data-distance')) || Infinity;

                switch (filterState.sortBy) {
                    case 'rating-desc':
                        const ratingB = parseFloat(b.querySelector('.rating-stars')?.title?.split(' ')[0]) || 0;
                        const ratingA = parseFloat(a.querySelector('.rating-stars')?.title?.split(' ')[0]) || 0;
                        return ratingB - ratingA;

                    case 'rating-asc':
                        const rating1 = parseFloat(a.querySelector('.rating-stars')?.title?.split(' ')[0]) || 0;
                        const rating2 = parseFloat(b.querySelector('.rating-stars')?.title?.split(' ')[0]) || 0;
                        return rating1 - rating2;

                    case 'price-asc':
                        return priceA - priceB;
                    case 'price-desc':
                        return priceB - priceA;
                    case 'capacity-asc':
                        return capacityA - capacityB;
                    case 'capacity-desc':
                        return capacityB - capacityA;
                    case 'name-asc':
                        return nameA.localeCompare(nameB);
                    case 'name-desc':
                        return nameB.localeCompare(nameA);
                    case 'distance-asc':
                        return distA - distB;
                    default:
                        return a.getAttribute('data-is-featured') === 'true' ? -1 : 1;
                }
            });

            // Reorder DOM
            sortedItems.forEach(item => turfContainer.appendChild(item));

            // Update UI
            noResults.classList.toggle('d-none', visibleItems > 0);
            loadingSpinner.classList.add('d-none');
        }, 300);
    }

    // Sort turfs by distance
    function sortByDistance(userLat, userLon) {
        loadingSpinner.classList.remove('d-none');
        setTimeout(() => {
            const items = Array.from(turfContainer.querySelectorAll('.turf-item'));
            items.forEach(item => {
                const lat = parseFloat(item.getAttribute('data-lat'));
                const lon = parseFloat(item.getAttribute('data-lon'));
                let distance = Infinity;

                if (!isNaN(lat) && !isNaN(lon)) {
                    distance = calculateDistance(userLat, userLon, lat, lon);
                    item.setAttribute('data-distance', distance);
                    const distanceElement = item.querySelector('.distance-info');
                    distanceElement.classList.remove('d-none');
                    distanceElement.querySelector('.value').textContent = `${distance.toFixed(1)} km`;
                } else {
                    item.setAttribute('data-distance', Infinity);
                    const distanceElement = item.querySelector('.distance-info');
                    distanceElement.classList.remove('d-none');
                    distanceElement.querySelector('.value').textContent = 'Unknown';
                }
            });

            filterState.userLat = userLat;
            filterState.userLon = userLon;
            filterState.distanceSort = true;
            filterState.sortBy = 'distance-asc';
            sortBySelect.value = 'distance-asc';
            sortBySelect.querySelector('option[value="distance-asc"]').classList.remove('d-none');
            applyFiltersAndSort();
        }, 500);
    }

    // Event listener for "Find Turfs Near Me"
    findNearbyBtn.addEventListener('click', () => {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(position => {
                const userLat = position.coords.latitude;
                const userLon = position.coords.longitude;
                Promise.all(Array.from(turfContainer.querySelectorAll('.turf-item')).map(item => fetchTurfCoordinates(item))).then(() => {
                    sortByDistance(userLat, userLon);
                });
            }, error => {
                alert('Unable to get your location. Please allow location access or search manually.');
                console.error('Geolocation error:', error);
            });
        } else {
            alert('Geolocation is not supported by your browser.');
        }
    });

    // Reset Filters
    function resetFilters() {
        filterState.searchQuery = '';
        filterState.showOnlyFeatured = false;
        filterState.priceRange = '';
        filterState.capacityRange = '';
        filterState.facility = '';
        filterState.sortBy = 'default';
        filterState.distanceSort = false;

        searchInput.value = '';
        featuredFilter.checked = false;
        priceRangeSelect.value = '';
        capacityFilter.value = '';
        facilityFilter.value = '';
        sortBySelect.value = 'default';
        sortBySelect.querySelector('option[value="distance-asc"]').classList.add('d-none');

        clearSearchBtn.classList.add('d-none');
        applyFiltersAndSort();
    }

    // Debounced applyFiltersAndSort
    const debouncedApplyFilters = debounce(applyFiltersAndSort, 300);

    // Event listeners for filters
    searchInput.addEventListener('input', () => {
        filterState.searchQuery = searchInput.value.trim().toLowerCase();
        clearSearchBtn.classList.toggle('d-none', filterState.searchQuery === '');
        debouncedApplyFilters();
    });

    clearSearchBtn.addEventListener('click', () => {
        searchInput.value = '';
        filterState.searchQuery = '';
        clearSearchBtn.classList.add('d-none');
        applyFiltersAndSort();
    });

    featuredFilter.addEventListener('change', () => {
        filterState.showOnlyFeatured = featuredFilter.checked;
        debouncedApplyFilters();
    });

    priceRangeSelect.addEventListener('change', () => {
        filterState.priceRange = priceRangeSelect.value;
        debouncedApplyFilters();
    });

    capacityFilter.addEventListener('change', () => {
        filterState.capacityRange = capacityFilter.value;
        debouncedApplyFilters();
    });

    facilityFilter.addEventListener('change', () => {
        filterState.facility = facilityFilter.value;
        debouncedApplyFilters();
    });

    sortBySelect.addEventListener('change', () => {
        filterState.sortBy = sortBySelect.value;
        applyFiltersAndSort();
    });

    resetFiltersBtn.addEventListener('click', resetFilters);

    // Initial load
    applyFiltersAndSort();
});