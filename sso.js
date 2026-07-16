    // Initialize Keycloak
    const keycloak = new Keycloak({
        url: 'https://login.spel.cz',
        realm: 'holding',
        //clientId: 'kepl-test'
        clientId: 'man-plmapps'
    });

    var parsedToken = {};


document.addEventListener("DOMContentLoaded", ssoLogin);

function ssoLogin() {
        keycloak.init({
        onLoad: 'login-required',
        redirectUri: window.location.href,
        checkLoginIframe: false
    }).then(function () {
        if (keycloak.authenticated) {
            console.log("auth ok");
            parsedToken = keycloak.tokenParsed;
            console.log(parsedToken);
        } else {
            console.log("neautorizovano");
        }
        setToken(parsedToken);
    }).catch(function () {
        console.log('Failed to initialize');
    });
}

