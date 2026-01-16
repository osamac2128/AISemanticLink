/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './admin/js/src/**/*.{js,jsx,ts,tsx}',
  ],
  theme: {
    extend: {
      colors: {
        // WordPress admin colors
        wp: {
          primary: '#2271b1',
          'primary-hover': '#135e96',
          secondary: '#3c434a',
          accent: '#d63638',
          success: '#00a32a',
          warning: '#dba617',
          info: '#72aee6',
        },
        // Phase states
        phase: {
          pending: '#94a3b8',
          active: '#3b82f6',
          complete: '#22c55e',
          error: '#ef4444',
        },
      },
      animation: {
        'pulse-slow': 'pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
        'stripe': 'stripe 1s linear infinite',
      },
      keyframes: {
        stripe: {
          '0%': { backgroundPosition: '0 0' },
          '100%': { backgroundPosition: '40px 0' },
        },
      },
    },
  },
  plugins: [],
  // Use JIT mode (default in Tailwind 3.x)
  mode: 'jit',
  // Prevent conflicts with WordPress admin styles
  important: '#vibe-ai-admin',
  corePlugins: {
    preflight: false,
  },
};
