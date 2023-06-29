
# Monitor script for php-fpm on GCE.

Inspired by [the munin plugin](https://gallery.munin-monitoring.org/plugins/munin-contrib/php_fpm_process/).  
It enables you to see charts for active/idle processes of the fpm pool.
This script is a porting it to the monitoring panel on Google Cloud Console.

## Usage: 

1. Make a copy config-example.php as config.php and edit it to point the correct location of php-fpm status URL.
2. Run script with your credential json file as an env like:  
   `>  GOOGLE_APPLICATION_CREDENTIALS=/path/to/my-google-service.json  php ./main.php`
3. Check the Monitoring section on Google Cloud Console. `php_fpm` entries can be found at `Metric Explorer` > `Global` > `Custom Metrics`.
4. Register it as a cron or systemd-timer script, to run every 5 minutes.

