import axios from 'axios';

// Configure axios for Laravel session-based authentication
const instance = axios.create({
    baseURL: '/',
    withCredentials: true, // Send cookies with requests
    headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest', // Laravel recognizes AJAX requests
    },
});

// Add CSRF token to all requests
instance.interceptors.request.use(
    (config) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (token) {
            config.headers['X-CSRF-TOKEN'] = token;
        }

        // For FormData, let axios set Content-Type automatically with boundary
        if (config.data instanceof FormData) {
            delete config.headers['Content-Type'];
        } else if (!config.headers['Content-Type']) {
            // For other requests, default to JSON
            config.headers['Content-Type'] = 'application/json';
        }

        return config;
    },
    (error) => {
        return Promise.reject(error);
    }
);

// Handle response errors
instance.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            console.error('Unauthorized - redirecting to login');
            window.location.href = '/login';
        }
        if (error.response?.status === 419) {
            console.error('CSRF token mismatch - refreshing page');
            window.location.reload();
        }
        return Promise.reject(error);
    }
);

export default instance;
