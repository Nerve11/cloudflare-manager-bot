# Cloudflare Manager Telegram Bot

A Telegram bot to manage Cloudflare domains and settings using Cloudflare's API. Provides a user-friendly Telegram interface for managing domains, DNS records, WAF rules, and redirects.

## Features

- **Domain Management**
  - List all domains with pagination
  - Add new domains (automatically enables Always Use HTTPS and disables ECH)
  - Delete domains
  - Toggle domain settings (HTTPS, ECH)

- **DNS Records Management**
  - View DNS records
  - Add, edit, delete DNS records
  - Support for A, AAAA, CNAME, TXT, MX, NS, SRV record types

- **WAF Rules Management**
  - View WAF (Firewall) rules
  - Add, edit, delete WAF rules
  - Set rule modes and priorities

- **Redirect Rules Management**
  - View redirect rules
  - Add, edit, delete redirect rules
  - Configure 301/302 redirects with various options

- **Search Functionality**
  - Search domains by name
  - Search by domain name without TLD
  - Direct domain settings access by typing the full domain name

## Requirements

- PHP 7.4 or higher
- Composer
- Telegram Bot token (get from [@BotFather](https://t.me/BotFather))
- Cloudflare API token with appropriate permissions

## Installation

1. Clone the repository:
   ```
   git clone https://github.com/yourusername/cloudflare-manager-bot.git
   cd cloudflare-manager-bot
   ```

2. Install dependencies:
   ```
   composer install
   ```

3. Edit the `config.php` file with your settings:
   - Add your Telegram bot token
   - Add your webhook URL
   - Add your Cloudflare API token and email

4. Set up a webhook for your Telegram bot:
   ```
   https://api.telegram.org/botYOUR_BOT_TOKEN/setWebhook?url=YOUR_WEBHOOK_URL
   ```

5. Make sure your web server is configured to handle PHP files and the webhook endpoint is accessible from the internet.

## Usage

Once the bot is set up, you can interact with it in Telegram using the following commands:

- `/start` or `/help` - Display help information
- `/domains` - List all your domains
- `/add <domain>` - Add a new domain
- `/search <term>` - Search for domains

You can also:
- Send a domain name to search for it
- Send a full domain name to access its settings directly

## Directory Structure

```
├── composer.json
├── config.php
├── webhook.php
├── src/
│   ├── Cloudflare/
│   │   └── API.php
│   ├── Telegram/
│   │   └── Bot.php
│   ├── Handlers/
│   │   ├── CommandHandler.php
│   │   └── CallbackHandler.php
│   └── Helpers/
│       └── Logger.php
├── logs/
└── data/
```

## Security Notes

- Keep your `config.php` file secure and out of public web directories
- Use a dedicated Cloudflare API token with limited permissions
- Consider implementing authentication for the bot to restrict access

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Acknowledgements

- [Cloudflare API Documentation](https://developers.cloudflare.com/api)
- [Telegram Bot API Documentation](https://core.telegram.org/bots/api) 