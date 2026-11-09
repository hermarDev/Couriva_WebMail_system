/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './public/**/*.php',
    './admin/**/*.php',
    './includes/**/*.php',
    './assets/js/**/*.js'
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          500: '#635bff',
          600: '#5148e8',
          700: '#4338ca'
        },
        coral: '#ff6b5f'
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif']
      },
      boxShadow: {
        brand: '0 16px 38px rgba(27, 24, 67, 0.10)'
      }
    }
  },
  plugins: []
};
