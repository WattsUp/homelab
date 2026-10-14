# Pi-hole DNSSEC incident, October 2026

## Finding

The root DNSSEC key-signing key changed on October 11, 2026. Both affected
Pi-hole containers run Pi-hole 5.18.2 and FTL 5.25.1 from the deprecated,
pinned `cbcrowe/pihole-unbound:2024.03.2` image. FTL's generated
`/etc/dnsmasq.d/01-pihole.conf` contains only the retired root trust anchor
with key tag 20326. Its logs consequently show `reply . is BOGUS DNSKEY` and
then report every dependent validation as BOGUS.

This is a confirmed common cause, not a connectivity or firewall failure. It
explains the timing and why two independent instances failed together. The
temporary GUI change restored resolution because it disabled this stale FTL
validation step, allowing answers from Unbound to pass through.

## DNSSEC assurance

Unbound is intended to remain the DNSSEC validator. DNS1's
`/var/lib/unbound/root.key` contains both KSK-2024 (38696) and the old key
(20326), and it resolves `google.com` while rejecting `dnssec-failed.org`.
Docker0's anchor initially contained 38696 but subsequently reverted to only
20326; it now returns `SERVFAIL` for valid names unless the client sets `+cd`.
The anchor is inside the container's non-persistent `/var/lib/unbound` path.
This proves the current Docker0 Unbound bootstrap is not safe after the
rollover. Both hosts enable `auto-trust-anchor-file` and
`harden-dnssec-stripped: yes`, and their host clocks report
`System clock synchronized: yes`; time is not the observed cause.

## Managed configuration

The role now pins the maintained `mpgirro/pihole-unbound:2026.07.2` image.
It migrates the deprecated Pi-hole v5 environment names to Pi-hole v6's
`FTLCONF_*` settings. `pihole_dnssec: false` becomes
`FTLCONF_dns_dnssec=false`, while `FTLCONF_dns_upstreams` retains Unbound as
the sole upstream on `127.0.0.1#5335`. Pi-hole v6 only reads the existing
managed `/etc/dnsmasq.d` local-record files when
`FTLCONF_misc_etc_dnsmasq_d=true`; the role sets this explicitly.

Run the following after review. The changed environment causes Ansible to
recreate the existing containers, so schedule this as two short, sequential
DNS interruptions rather than both at once:

```sh
ansible-playbook -i hosts.yaml playbooks/dns.yaml --limit docker0.nibbler
ansible-playbook -i hosts.yaml playbooks/dns.yaml --limit dns1.nibbler
ansible-playbook -i hosts.yaml playbooks/dns_verify.yaml
```

To verify persistence, recreate one Pi-hole container during a maintenance
window, rerun its limited `dns.yaml` deployment, then run
`dns_verify.yaml`. Do not recreate both resolvers together.

Do not deploy the FTL-only change while Unbound is failing. Docker0's current
Unbound `root.key` has only old key tag 20326 and returns `SERVFAIL` for a
valid domain unless the client sets `+cd`. The v6 image upgrade is required to
restore an up-to-date Unbound trust-anchor bootstrap as well as the FTL anchor.
Deploy and validate Docker0 first. Continue to DNS1 only after all checks pass.
Rollback is to restore the prior `cbcrowe/pihole-unbound:2024.03.2` image and
the backed-up `/etc/pihole/pihole` directory. That rollback restores service
only if its Unbound `root.key` contains KSK-2024, so it is not a long-term
DNSSEC-safe fallback.

## Monitoring

Uptime Kuma now performs an A-record lookup of `google.com` through each
Pi-hole every 60 seconds. DNS monitor failures include `SERVFAIL`, unlike the
previous TCP port monitors. It reuses the existing Discord notification setup.
Unbound intentionally listens only on container loopback, and the present
Uptime Kuma DNS monitor cannot request DNSSEC data or interpret the negative
`dnssec-failed.org` test. `dns_verify.yaml` performs those direct checks on
each host. Continuous DNSSEC-specific monitoring would require a local probe
or a Uptime Kuma push monitor, neither of which exists in this deployment.

## Maintenance recommendation

`cbcrowe/pihole-unbound` is deprecated and Pi-hole v5 is unsupported. The
maintained successor is `mpgirro/pihole-unbound`. Back up
`/etc/pihole/pihole`, deploy and test one resolver first, run
`dns_verify.yaml`, then upgrade the second.
