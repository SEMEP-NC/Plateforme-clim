FROM smallstep/step-ca:latest

USER root

COPY init-ca.sh /init-ca.sh

RUN chmod +x /init-ca.sh

ENTRYPOINT ["/init-ca.sh"]