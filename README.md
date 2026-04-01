# Thorium Reforged Website

A full-featured WoW private server community website built with PHP, JavaScript, and Tailwind CSS. Includes character armory, real-time chat, voting system, donations, and Discord integration.

## Features

### Core Systems
- **Character Armory** - Browse character stats, gear, enchants, and achievements
- **Real-time Chat** - In-game chat system with moderation
- **Voting System** - Top-list voting with weekend multipliers
- **Donation System** - PayPal integration with point rewards
- **News & Announcements** - Server updates and patch notes
- **Discord Integration** - Guild management and notifications

### Technical Features
- **Multi-Database** - Separate databases for website, characters, and authentication
- **Responsive Design** - Mobile-first with Tailwind CSS
- **Security** - CSRF protection, rate limiting, SQL safety
- **Theme System** - Modular theme architecture (Emerald Forest, Test)
- **Performance** - Query caching, lazy loading, optimized assets
- **Admin Dashboard** - Server management and moderation tools

## Project Structure

```
thorium-wow/
├── app/                      # Application code
│   ├── Core/                 # Core framework (DB, Auth, Theme)
│   ├── Repositories/         # Data access layer
│   ├── Services/             # Business logic
│   ├── Security/             # Auth, CSRF, Rate limiting
│   └── *_repo.php            # Legacy repository files
├── themes/                   # Theme packages
│   ├── thorium-emeraldforest/
│   └── thorium-test/
├── pages/                    # Page templates
├── partials/                 # Reusable UI components
├── views/                    # View templates
├── .env.example              # Environment template (copy to .env)
├── composer.json             # PHP dependencies
├── package.json              # Node dependencies (Tailwind)
└── tailwind.config.js        # Tailwind CSS configuration
```

## Setup

### Requirements
- PHP 8.0+
- MySQL 5.7+
- Node.js 14+ (for Tailwind CSS)
- Composer

### Installation

1. **Clone and install dependencies:**
   ```bash
   git clone https://github.com/ChubsDeveloper/thorium-website.git
   cd thorium-website
   composer install
   npm install
   ```

2. **Configure environment:**
   ```bash
   cp .env.example .env
   # Edit .env with your database credentials, PayPal keys, etc.
   ```

3. **Build CSS:**
   ```bash
   npm run build   # Production
   npm run dev     # Watch mode during development
   ```

4. **Database setup:**
   - Run migration scripts from `app/migrations/` if available
   - Import initial data into character and auth databases

5. **Web server:**
   - Point web root to the project directory
   - Ensure `.htaccess` is enabled (Apache)
   - Configure HTTPS with proper SSL certificates

## Development

### Build Tailwind CSS
```bash
npm run dev    # Watch mode for development
npm run build  # Minified production build
```

### Code Style
- PHP: PSR-12 compliant
- JavaScript: ES6+ with minimal framework usage
- CSS: Tailwind utility-first approach

### Security
- All database queries use prepared statements
- CSRF tokens on all forms
- Rate limiting on API endpoints
- Input validation and sanitization throughout

## Configuration

Key settings in `.env`:
- **DATABASE** - Character, auth, and website databases
- **PAYPAL** - Donation system integration
- **DISCORD** - Guild embed and webhooks
- **SECURITY** - Session timeouts, rate limits, password policies
- **THEME** - Default theme and UI effects (fog, particles)
- **VOTING** - Point multipliers and reward schedule

## Database Schema

### Website Database
- Users and authentication
- News and announcements
- Donations and point ledger
- Voting records
- Chat messages and pins
- Settings and configuration

### Character & Auth Databases
- Player accounts and characters
- Inventory and equipment
- Achievement tracking
- Realm information

## Performance Considerations

- Query results cached where appropriate
- Lazy-loaded JavaScript for animations
- Optimized image sizes with responsive srcset
- Minified CSS and JavaScript in production
- Database connection pooling

## Themes

### Emerald Forest (Default)
- Green-blue color scheme with nature-inspired effects
- Fog effects with customizable opacity
- Particle system (fireflies, leaves, petals, sparkles, snow)
- Hero parallax and smooth scrolling animations

### Test
- Alternative theme for development and testing

## API Endpoints

Main endpoints (see controllers for full list):
- `/api/character/{id}` - Character details and equipment
- `/api/vote` - Voting system
- `/api/chat` - Real-time chat messages
- `/api/donations` - Donation system
- `/api/news` - Latest news posts

## Deployment

1. Set `DEBUG=false` in `.env`
2. Run `npm run build` for optimized CSS
3. Ensure `FORCE_HTTPS=true` and `SECURE_COOKIES=true`
4. Configure database backups and monitoring
5. Set up log rotation for application logs
6. Enable CORS headers if needed for API access

## Contributing

Bug reports and suggestions welcome. Please ensure code follows the existing style conventions.

## License

These scripts are custom implementations for Thorium Reforged.
