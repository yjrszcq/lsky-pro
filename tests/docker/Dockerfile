FROM python:3.12-slim
RUN apt-get update && apt-get install -y --no-install-recommends openssh-server \
    && rm -rf /var/lib/apt/lists/* \
    && pip install --no-cache-dir pyftpdlib==2.1.0 WsgiDAV==4.3.3 cheroot==10.0.1 'moto[server]==5.1.15' aiosmtpd==1.4.6 \
    && mkdir -p /run/sshd /data/ftp /data/webdav /data/sftp \
    && useradd -m -d /data/sftp integration \
    && echo 'integration:container-test-password' | chpasswd \
    && chown integration:integration /data/sftp
COPY services.py /services.py
CMD ["python", "/services.py"]
