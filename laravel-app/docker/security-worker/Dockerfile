FROM php:8.4-cli-bookworm

COPY docker/security-worker/clamav.version /tmp/clamav.version
COPY docker/security-worker/freshclam.conf /tmp/freshclam.conf

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        gnupg \
        libzip-dev \
        unzip \
    && . /tmp/clamav.version \
    && case "${CLAMAV_VERSION}" in \
        "${CLAMAV_RELEASE_LINE}".*) ;; \
        *) \
            echo "ClamAV version ${CLAMAV_VERSION} is outside release line ${CLAMAV_RELEASE_LINE}" >&2; \
            exit 1 \
            ;; \
    esac \
    && case "$(dpkg --print-architecture)" in \
        arm64) CLAMAV_ARCH="aarch64" ;; \
        amd64) CLAMAV_ARCH="x86_64" ;; \
        *) \
            echo "Unsupported architecture: $(dpkg --print-architecture)" >&2; \
            exit 1 \
            ;; \
    esac \
    && CLAMAV_PACKAGE="clamav-${CLAMAV_VERSION}.linux.${CLAMAV_ARCH}.deb" \
    && CLAMAV_BASE_URL="https://www.clamav.net/downloads/production" \
    && curl --fail --location --silent --show-error \
        "${CLAMAV_BASE_URL}/${CLAMAV_PACKAGE}" \
        --output "/tmp/${CLAMAV_PACKAGE}" \
    && curl --fail --location --silent --show-error \
        "${CLAMAV_BASE_URL}/${CLAMAV_PACKAGE}.sig" \
        --output "/tmp/${CLAMAV_PACKAGE}.sig" \
    && curl --fail --location --silent --show-error \
        "https://raw.githubusercontent.com/Cisco-Talos/clamav-documentation/main/src/manual/cisco-talos.gpg" \
        --output /tmp/cisco-talos.gpg \
    && mkdir -m 700 /tmp/clamav-gnupg \
    && export GNUPGHOME=/tmp/clamav-gnupg \
    && gpg --batch --import /tmp/cisco-talos.gpg \
    && gpg --batch --verify \
        "/tmp/${CLAMAV_PACKAGE}.sig" \
        "/tmp/${CLAMAV_PACKAGE}" \
    && apt-get install -y --no-install-recommends \
        "/tmp/${CLAMAV_PACKAGE}" \
    && groupadd \
        --system \
        --gid 102 \
        clamav \
    && useradd \
        --system \
        --uid 100 \
        --gid 102 \
        --home-dir /var/lib/clamav \
        --shell /usr/sbin/nologin \
        clamav \
    && mkdir -p /var/lib/clamav /usr/local/etc \
    && install \
        -m 0644 \
        /tmp/freshclam.conf \
        /usr/local/etc/freshclam.conf \
    && chown -R clamav:clamav /var/lib/clamav \
    && docker-php-ext-install \
        pcntl \
        pdo_mysql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf \
        /tmp/clamav.version \
        /tmp/freshclam.conf \
        /tmp/clamav-gnupg \
        /tmp/cisco-talos.gpg \
        /tmp/clamav-*.deb \
        /tmp/clamav-*.deb.sig \
        /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html