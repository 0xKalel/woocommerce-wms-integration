# WMS Integration Configuration

## Environment Configuration

### .env File Setup
Copy `.env.example` to `.env` and configure:

```bash
# WordPress Settings
WP_ADMIN_USER=admin
WP_ADMIN_EMAIL=admin@example.com
WP_ADMIN_PASSWORD=your-secure-password

# Database Settings
MYSQL_ROOT_PASSWORD=your-root-password
MYSQL_DATABASE=wordpress
MYSQL_USER=wordpress
MYSQL_PASSWORD=your-db-password

# WMS API Settings
WMS_USERNAME=your-wms-username
WMS_PASSWORD=your-wms-password
WMS_CUSTOMER_ID=your-customer-id
WMS_CODE=your-wms-code
WMS_API_URL=https://eu-dev.middleware.ewarehousing-solutions.com/

# Webhook Development (for local testing)
NGROK_AUTHTOKEN=your-ngrok-token
```

## WordPress Admin Configuration

### WMS Connection Settings
Navigate to **WooCommerce → WMS Integration → Connection**:

1. **API URL**: WMS endpoint (e.g., `https://eu-dev.middleware.ewarehousing-solutions.com/`)
2. **Username**: Your WMS account username
3. **Password**: Your WMS account password
4. **Customer ID**: Your WMS customer identifier
5. **Customer Code**: Your WMS customer code

### Test Connection
Click **"Test Connection"** to verify credentials and connectivity.

## Webhook Configuration

### Production Setup
1. **Webhook URL**: `https://yourdomain.com/wp-json/wc-wms/v1/webhook`
2. **Secret**: Generate via **Webhooks tab → Generate New Secret**
3. **Events**: Register all webhooks via **"Register All Webhooks with WMS"**

### Development Setup
For local development with ngrok:

```bash
# Start webhook testing environment
make webhook-start

# Check webhook status
make webhook-status

# Register webhooks with WMS
make webhook-register
```

## Cron Configuration

### WordPress Cron (Recommended)
The plugin uses WordPress cron by default with these schedules:

- **Order queue**: Every 2 minutes
- **Stock sync**: Every hour
- **Order sync**: Every 2 hours  
- **Inbound sync**: Every 4 hours
- **Shipment sync**: Every 3 hours

### System Cron (Production)
For better reliability, disable WordPress cron and use system cron:

```php
// wp-config.php
define('DISABLE_WP_CRON', true);
```

```bash
# crontab -e
*/2 * * * * curl -s https://yourdomain.com/wp-cron.php >/dev/null
```

## Performance Settings

### Rate Limiting
Default settings in `WC_WMS_Constants`:

```php
const RATE_LIMIT_DEFAULT = 3600;        // Requests per hour
const RATE_LIMIT_THRESHOLD = 10;        // Remaining requests threshold
const REQUEST_TIMEOUT = 30;             // Request timeout (seconds)
```

### Queue Settings
```php
const MAX_RETRIES = 3;                   // Maximum retry attempts
const RETRY_INTERVALS = [30, 120, 300, 900, 3600]; // Retry delays
```

## Security Configuration

### Webhook Security
- **HMAC Validation**: Automatically enabled
- **Secret Rotation**: Generate new secrets regularly
- **IP Whitelisting**: Configure firewall rules for WMS IPs

### API Security
- **HTTPS Only**: Always use HTTPS endpoints
- **Credential Storage**: Stored encrypted in WordPress database
- **Access Control**: Restrict admin access to authorized users

## Development Configuration

### Debug Mode
Enable detailed logging:

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### Docker Environment
```bash
# Start development environment
make setup

# With test data
make setup-with-test-data

# Start webhook testing
make webhook-start
```

## Monitoring Configuration

### Health Checks
Monitor these endpoints:
- **Connection Status**: WooCommerce → WMS Integration → Connection
- **Webhook Status**: WooCommerce → WMS Integration → Webhooks  
- **Sync Status**: WooCommerce → WMS Integration → Synchronization

### Log Monitoring
Check logs in:
- **WordPress**: `/uploads/wc-logs/`
- **Docker**: `logs/wordpress/debug.log`
- **Webhook**: `logs/webhook/`

## Common Configuration Issues

### Authentication Failures
- Verify WMS credentials are correct
- Check API endpoint URL
- Ensure customer ID and code match

### Webhook Failures
- Verify webhook URL is publicly accessible
- Check webhook secret configuration
- Confirm WMS can reach your server

### Sync Issues
- Check cron job execution
- Verify rate limits not exceeded
- Review error logs for specific failures

## Production Checklist

- [ ] Configure proper .env file
- [ ] Set strong passwords
- [ ] Enable HTTPS
- [ ] Configure system cron
- [ ] Set up monitoring
- [ ] Test all webhook events
- [ ] Verify backup procedures
- [ ] Configure log rotation
