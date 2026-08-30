Resolutiva • Página de Gerenciamento da Instância Evolution (Standalone)

1) Estrutura
- evolution_instance.php
- assets/
  - logo-resolutiva-branca.png
  - rv.png

2) Como configurar (RECOMENDADO)
Defina variáveis de ambiente no servidor (Apache/Nginx + PHP):

EVOLUTION_SERVER_URL="https://SEU-EVOLUTION:PORTA"
EVOLUTION_API_KEY="SUA-APIKEY"
EVOLUTION_SSL_VERIFY="1"   (opcional; 0 desativa verificação SSL)

Exemplos:
- Apache (VirtualHost):
  SetEnv EVOLUTION_SERVER_URL "https://evolution.seudominio.com"
  SetEnv EVOLUTION_API_KEY "xxxxx"
  SetEnv EVOLUTION_SSL_VERIFY "1"

- Nginx (fastcgi_param):
  fastcgi_param EVOLUTION_SERVER_URL "https://evolution.seudominio.com";
  fastcgi_param EVOLUTION_API_KEY "xxxxx";
  fastcgi_param EVOLUTION_SSL_VERIFY "1";

3) Como usar
Abra no navegador:
  /evolution_instance.php?instance=nome_da_instancia

4) Observações de segurança
- A API Key fica somente no servidor (PHP).
- O JS faz chamadas para o próprio arquivo com ?api=1, e o PHP encaminha para o Evolution.

5) Endpoints suportados (fallback)
Status:
- /instance/connectionState/{instance}
- /instance/connection-state/{instance}
- /instance/status/{instance}
- /instance/info/{instance}
- /instance/{instance}

QR Code:
- /instance/connect/{instance} (GET/POST)
- /instance/qrcode/{instance}
- /instance/qr/{instance}
- /instance/qr-code/{instance}
- /qrcode/{instance}

Logout/Disconnect:
- /instance/logout/{instance} (GET/POST)
- /instance/disconnect/{instance} (GET/POST)
