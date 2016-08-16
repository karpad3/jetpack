#!/bin/bash

# This prepares a developer checkout of WordPress for running the test suite on Travis

mysql -u root -e "CREATE DATABASE wordpress_tests;"

CURRENT_DIR=$(pwd)

for WP_SLUG in 'master' 'latest' 'previous'; do
    echo "Preparing $WP_SLUG WordPress...";

    cd $CURRENT_DIR

    case $WP_SLUG in
	master)
	    git clone --depth=1 --branch master git://develop.git.wordpress.org/ /tmp/wordpress-master
	    ;;
	latest)
	    git clone --depth=1 --branch `php ./get-wp-version.php` git://develop.git.wordpress.org/ /tmp/wordpress-latest
	    ;;
	previous)
	    git clone --depth=1 --branch `php ./get-wp-version.php --previous` git://develop.git.wordpress.org/ /tmp/wordpress-previous
	    ;;
    esac

    cp $PLUGIN_SLUG "/tmp/wordpress-$WP_SLUG/src/wp-content/plugins/$PLUGIN_SLUG"
    cd /tmp/wordpress-$WP_SLUG

    cp wp-tests-config-sample.php wp-tests-config.php
    sed -i "s/youremptytestdbnamehere/wordpress_tests/" wp-tests-config.php
    sed -i "s/yourusernamehere/root/" wp-tests-config.php
    sed -i "s/yourpasswordhere//" wp-tests-config.php
    cd "/tmp/wordpress/src/wp-content/plugins/$PLUGIN_SLUG"


    echo "Done!";
done

exit 0;
