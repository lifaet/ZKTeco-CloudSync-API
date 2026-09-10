from zk import ZK
from config import DEVICE_IP, PORT

zk = ZK(
    DEVICE_IP,
    port=PORT,
    timeout=10,
    force_udp=True
)

try:
    conn = zk.connect()

    print("Connected to device")

    print("Restarting device...")
    conn.restart()

    print("Restart command sent successfully")

except Exception as e:
    print("Error:", e)
