# PHP 8.3 CLI
FROM php:8.3-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libxml2-dev \
    sqlite3 \
    libsqlite3-dev \
    unzip \
    libaio1t64 \
    libnsl2 \
    libonig-dev \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Fix libaio mapping for Oracle
RUN ln -s /usr/lib/x86_64-linux-gnu/libaio.so.1t64 /usr/lib/x86_64-linux-gnu/libaio.so.1

# Install Oracle Instant Client (Required for OCI8)
RUN mkdir -p /opt/oracle && cd /opt/oracle && \
    curl -L -o basic.zip https://download.oracle.com/otn_software/linux/instantclient/2112000/instantclient-basic-linux.x64-21.12.0.0.0dbru.zip && \
    curl -L -o sdk.zip https://download.oracle.com/otn_software/linux/instantclient/2112000/instantclient-sdk-linux.x64-21.12.0.0.0dbru.zip && \
    unzip basic.zip && unzip sdk.zip && \
    rm *.zip

# Set Oracle Environment Variables
ENV LD_LIBRARY_PATH="/opt/oracle/instantclient_21_12"
ENV ORACLE_HOME="/opt/oracle/instantclient_21_12"

# Install PHP extensions including OCI8 for PHP 8
RUN docker-php-ext-configure oci8 --with-oci8=instantclient,/opt/oracle/instantclient_21_12 \
    && docker-php-ext-install oci8 curl soap xml mbstring pdo_sqlite

WORKDIR /var/www/html/scarless