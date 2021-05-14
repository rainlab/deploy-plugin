
function generatePrivateKeyFromForge(callback) {
    var param = {
        bits: 2048,
        workerScript: RAINLAB_DEPLOY_WORKER_PATH,
        workers: -1
    };

    forge.pki.rsa.generateKeyPair(param, function (err, keypair) {
        var pemPublic = forge.pki.publicKeyToPem(keypair.publicKey),
            pemPrivate = forge.pki.privateKeyToPem(keypair.privateKey);

        // console.log(pemPublic);
        // console.log(pemPrivate);

        callback && callback(pemPrivate);
    });
}

function generatePrivateFromCryptoSubtle(callback) {
    var param = {
        name: "RSASSA-PKCS1-v1_5",
        modulusLength: 2048,
        publicExponent: new Uint8Array([0x01, 0x00, 0x01]),
        hash: {name: "SHA-256"}
    };

    window.crypto.subtle
        .generateKey(param, true, ["sign", "verify"])
        .then(function(key){
            // console.log(key.publicKey);
            // console.log(key.privateKey);

            window.crypto.subtle.exportKey(
                "pkcs8",
                key.privateKey
            )
            .then(function(keydata){
                callback && callback(keydata);
            })
            .catch(function(err){
                console.error(err);
            });
        })
        .catch(function(err){
            console.error(err);
        });
}
