<?php

return [
	'pool' => [
		'label' => 'www',
		// Defined as pm.status_path in /etc/php-fpm.d/*.comf 
		'status_url' => 'http://localhost/php-fpm-status',
	]
];
