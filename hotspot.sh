sudo pkill dnsmasq
sudo systemctl restart NetworkManager
nmcli connection delete Hotspot 
nmcli device wifi hotspot ifname wlan0 ssid test password 12345678
