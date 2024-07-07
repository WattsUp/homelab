#!/bin/sh

# Install roles from galaxy
ansible-galaxy install -r requirements.yaml

# Run everything
ansible-playbook -i hosts.yaml site.yaml
