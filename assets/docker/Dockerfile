# This dockerfile takes the current users uid/gid at build time and adjusts reality
# so that the running user for www-data is actually the same as the launching user.
FROM singularo/s2i-shepherd-drupal:8.0

ARG USER_ID
ARG GROUP_ID
ARG PHP_VERSION="8.0"

# Need to switch from www-data to root to do the change of uid
USER 0:0

# Remove existing www user (both) and group (dialout is the users group on mac).
RUN \
if [ ${USER_ID:-0} -ne 0 ] && [ ${GROUP_ID:-0} -ne 0 ]; then \
    userdel -f www-data \
    && groupdel dialout \
    && if getent group www-data ; then groupdel www-data; fi \
    && groupadd -g ${GROUP_ID} www-data \
    && useradd -l -u ${USER_ID} -g www-data www-data \
    && install -d -m 0755 -o www-data -g www-data /home/www-data \
    && mkdir -p /code /shared \
    && chown --changes --no-dereference --recursive \
        ${USER_ID}:${GROUP_ID} \
        /var/www \
        /run/lock \
        /var/run/apache2 \
        /var/log/apache2 \
        /var/lock/apache2 \
        /code \
        /shared; \
fi

# Upgrade all currently installed packages and install additional packages.
RUN apt-get update \
&& apt-get -y install --no-install-recommends git libnotify-bin openssh-client sudo unzip vim wget  \
  php${PHP_VERSION}-sqlite3 php${PHP_VERSION}-xdebug php${PHP_VERSION}-cli \
&& sed -ri 's/^zend.assertions\s*=\s*-1/zend.assertions = 1/g' /etc/php/${PHP_VERSION}/cli/php.ini \
&& apt-get -y autoremove && apt-get -y autoclean && apt-get clean && rm -rf /var/lib/apt/lists /tmp/* /var/tmp/*

# Install Composer.
RUN wget -q https://getcomposer.org/installer -O - | php -d allow_url_fopen=On -- --install-dir=/usr/local/bin --filename=composer

COPY ./docker/xdebug.ini /etc/php/${PHP_VERSION}/mods-available/xdebug.ini
COPY ./docker/custom.ini /etc/php/${PHP_VERSION}/mods-available/custom.ini

RUN echo "www-data ALL=(ALL) NOPASSWD: ALL" > /etc/sudoers.d/www-data \
&& echo "Set disable_coredump false" >> /etc/sudo.conf

# Enable xdebug & custom settings
RUN phpenmod -v ALL -s ALL xdebug
RUN phpenmod -v ALL -s ALL custom

USER www-data
