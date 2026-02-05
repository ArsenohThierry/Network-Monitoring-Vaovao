#!/bin/bash
# setup_traffic_control.sh - Run this ONCE to initialize

INTERFACE="wlan0"  # Change to your hotspot interface name

# Clear any existing rules
sudo tc qdisc del dev $INTERFACE root 2>/dev/null
sudo tc qdisc del dev $INTERFACE ingress 2>/dev/null

# Setup Hierarchical Token Bucket (HTB) for download limiting
sudo tc qdisc add dev $INTERFACE root handle 1: htb default 12

# Create root class with your total bandwidth
# Adjust 20mbit to your actual internet speed
sudo tc class add dev $INTERFACE parent 1: classid 1:1 htb rate 20mbit ceil 20mbit

# Create default class for unlimited devices
sudo tc class add dev $INTERFACE parent 1:1 classid 1:12 htb rate 20mbit ceil 20mbit

# Setup ingress (upload) limiting
sudo tc qdisc add dev $INTERFACE handle ffff: ingress
sudo tc filter add dev $INTERFACE parent ffff: protocol ip prio 50 u32 \
    match ip src 0.0.0.0/0 police rate 10mbit burst 10k drop flowid :1

echo "Traffic control setup complete on interface $INTERFACE"