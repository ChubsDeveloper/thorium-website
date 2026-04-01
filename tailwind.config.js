/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './app/**/*.php',
    './public/**/*.html',
    './resources/**/*.{html,js,php}',
    './themes/**/*.php',
    './themes/**/*.html',
  ],
  safelist: [
    { pattern: /^nfx(-.*)?$/ },
    { pattern: /^vip-(chip|l[0-8])(-.*)?$/ },
    { pattern: /^action-chip(--.*)?$/ },
    { pattern: /^btn(-.*)?$/ },
    { pattern: /^(animate|animation)-/ },
    { pattern: /^role-/ },
  ],
  theme: {
    extend: {},
  },
  plugins: [],
};