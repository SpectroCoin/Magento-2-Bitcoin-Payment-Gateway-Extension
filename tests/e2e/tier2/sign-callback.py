#!/usr/bin/env python3
"""Sign a legacy SpectroCoin callback exactly as the merchant API does.

The extension verifies an RSA-SHA1 signature over http_build_query() of eleven
fields, in this order, using the amounts as its own getters format them - so
both the field order and the number formatting have to match or the signature
is rejected and nothing is proven.
"""
import base64
import json
import subprocess
import sys
import urllib.parse


def fmt(amount):
    """Mirror FormattingUtil::formatCurrency - '0.0#######'."""
    value = float(amount)
    trimmed = ('%.8f' % value).rstrip('0')
    decimals = len(trimmed.split('.')[1]) if '.' in trimmed else 0
    return ('%.' + str(max(decimals, 1)) + 'f') % value


args = json.loads(sys.argv[1])
key_path = sys.argv[2]

signed = [
    ('merchantId', args['merchantId']),
    ('apiId', args['apiId']),
    ('orderId', args['orderId']),
    ('payCurrency', args['payCurrency']),
    ('payAmount', fmt(args['payAmount'])),
    ('receiveCurrency', args['receiveCurrency']),
    ('receiveAmount', fmt(args['receiveAmount'])),
    ('receivedAmount', fmt(args['receivedAmount'])),
    ('description', args['description']),
    ('orderRequestId', args['orderRequestId']),
    ('status', args['status']),
]

data = urllib.parse.urlencode(signed)
proc = subprocess.run(['openssl', 'dgst', '-sha1', '-sign', key_path],
                      input=data.encode(), capture_output=True)
if proc.returncode != 0:
    sys.stderr.write(proc.stderr.decode())
    sys.exit(1)

body = dict(signed)
body['userId'] = args['userId']
body['merchantApiId'] = args['merchantApiId']
body['sign'] = args.get('sign_override') or base64.b64encode(proc.stdout).decode()
print(urllib.parse.urlencode(body))
