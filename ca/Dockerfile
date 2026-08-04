FROM smallstep/step-ca:latest

USER root

COPY init-ca.sh /usr/local/bin/init-ca.sh

RUN chmod 755 /usr/local/bin/init-ca.sh \
    && mkdir -p /export

ENTRYPOINT ["/usr/local/bin/init-ca.sh"]