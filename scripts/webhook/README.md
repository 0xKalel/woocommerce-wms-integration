# Webhook Testing Setup with ngrok

This directory contains scripts and configuration for webhook testing using ngrok to provide HTTPS endpoints required by the eWarehousing WMS.

## Quick Setup

1. **Get ngrok authtoken:**
   - Sign up at https://ngrok.com/
   - Get your authtoken from https://dashboard.ngrok.com/get-started/your-authtoken

2. **Configure environment:**
   ```bash
   # Add to your .env file
   NGROK_AUTHTOKEN=your_token_here
   ```

3. **Start webhook testing:**
   ```bash
   make webhook-start
   # or
   ./scripts/webhook/webhook-dev.sh start
   ```

4. **Get your public webhook URL:**
   - Visit http://localhost:4040 (ngrok dashboard)
   - Use the HTTPS URL for webhook configuration

## Available Commands

### Make Commands (Recommended)
```bash
make webhook-start      # Start webhook testing environment
make webhook-stop       # Stop webhook testing
make webhook-status     # Check current status
make webhook-test       # Test webhook endpoints
make webhook-logs       # View webhook logs
make webhook-register   # Register webhooks with WMS
```

### Direct Script Usage
```bash
./scripts/webhook/webhook-dev.sh start
./scripts/webhook/webhook-dev.sh status
./scripts/webhook/webhook-dev.sh test
./scripts/webhook/webhook-dev.sh register
./scripts/webhook/webhook-dev.sh stop
```

### Dev Script Integration
```bash
./scripts/dev.sh webhook-start
./scripts/dev.sh webhook-status
./scripts/dev.sh webhook-test
```

## Architecture

### Docker Services
- **ngrok**: Provides HTTPS tunnel to WordPress container
- **ngrok-monitor**: Monitors tunnel URL changes and logs them
- **wordpress**: Your WordPress installation (accessible via ngrok)

### File Structure
```
scripts/webhook/
├── webhook-dev.sh          # Main webhook development script
├── monitor-ngrok.sh        # ngrok URL monitoring service
├── setup-check.sh          # Setup verification script
└── README.md               # This file

config/
└── ngrok.yml               # ngrok configuration

logs/webhook/               # Webhook-related logs
├── webhook-urls.txt        # Current webhook URLs
├── ngrok-monitor.log       # ngrok monitoring logs
└── current-url.txt         # Latest ngrok URL
```

## Workflow

1. **Start webhook testing:**
   ```bash
   make webhook-start
   ```
   This will:
   - Start ngrok tunnel
   - Update WordPress site URLs
   - Display webhook endpoints
   - Save URLs to log files

2. **Configure WMS webhooks:**
   - Use the HTTPS URL from ngrok dashboard
   - Configure these endpoints in WMS portal:
     - Main webhook: `https://your-url.ngrok.io/wp-json/wc-wms/v1/webhook`
     - Test endpoint: `https://your-url.ngrok.io/wp-json/wc-wms/v1/webhook/test`

3. **Test webhooks:**
   ```bash
   make webhook-test
   ```

4. **Register with WMS:**
   ```bash
   make webhook-register
   ```

5. **Stop testing:**
   ```bash
   make webhook-stop
   ```
   This will:
   - Stop ngrok tunnel
   - Reset WordPress URLs to localhost
   - Clean up environment

## Configuration Files

### ngrok.yml
Configures ngrok tunnels:
- Main tunnel: HTTPS only (required by WMS)
- Alternative tunnel: HTTP/HTTPS for testing
- Dashboard: Available at http://localhost:4040

### Environment Variables
```bash
# Required
NGROK_AUTHTOKEN=your_token_here

# Auto-generated
NGROK_URL=https://abc123.ngrok.io
```

## Monitoring

### ngrok Dashboard
- URL: http://localhost:4040
- Shows active tunnels
- Request inspection
- Replay requests

### Log Files
- `logs/webhook/webhook-urls.txt` - Current webhook URLs
- `logs/webhook/ngrok-monitor.log` - ngrok monitoring logs
- `logs/webhook/current-url.txt` - Latest ngrok URL

### WordPress Logs
- Standard WordPress debug logs include webhook events
- WMS integration plugin logs all webhook activity

## Troubleshooting

### Common Issues

1. **NGROK_AUTHTOKEN not set:**
   ```bash
   # Add to .env file
   NGROK_AUTHTOKEN=your_token_here
   ```

2. **ngrok tunnel not starting:**
   ```bash
   # Check ngrok container logs
   docker-compose logs ngrok
   
   # Verify authtoken
   make webhook-status
   ```

3. **Webhook endpoints not responding:**
   ```bash
   # Test endpoints
   make webhook-test
   
   # Check WordPress status
   curl http://localhost:8000/wp-json/wc-wms/v1/webhook/test
   ```

4. **WordPress URLs not updating:**
   ```bash
   # Manually reset URLs
   docker-compose run --rm wpcli wp option update home "https://your-url.ngrok.io"
   docker-compose run --rm wpcli wp option update siteurl "https://your-url.ngrok.io"
   ```

### Debug Commands

```bash
# Check setup
./scripts/webhook/setup-check.sh

# View all logs
make webhook-logs

# Check container status
docker-compose --profile webhook-testing ps

# Test ngrok API
curl http://localhost:4040/api/tunnels

# Get current ngrok URL
./scripts/webhook/webhook-dev.sh url
```

## Security Notes

- ngrok tunnels are temporary and change on restart
- URLs are automatically updated in WordPress
- Webhook signature validation is enforced (configurable in dev mode)
- All webhook traffic is logged for debugging

## Integration with WMS

1. **Start webhook testing**
2. **Copy HTTPS URL from ngrok dashboard**
3. **Configure in WMS portal:**
   - Webhook URL: `https://your-url.ngrok.io/wp-json/wc-wms/v1/webhook`
   - Events: order.created, order.updated, stock.updated, shipment.created
4. **Test webhook delivery**
5. **Register webhooks via plugin:** `make webhook-register`

## Production Notes

This setup is for development/testing only. In production:
- Use a proper domain with SSL certificate
- Configure webhooks directly with production URLs
- Remove ngrok dependency
- Use proper webhook signature validation
