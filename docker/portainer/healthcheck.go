package main

import (
	"crypto/tls"
	"fmt"
	"net/http"
	"os"
	"time"
)

func main() {
	client := &http.Client{
		Timeout: 4 * time.Second,
		Transport: &http.Transport{
			// Portainer creates a self-signed certificate. This probe only
			// accesses the fixed loopback address inside its own container.
			TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
		},
		CheckRedirect: func(req *http.Request, via []*http.Request) error {
			return http.ErrUseLastResponse
		},
	}
	response, err := client.Get("https://127.0.0.1:9443/api/system/status")
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		fmt.Fprintln(os.Stderr, "Portainer status:", response.StatusCode)
		os.Exit(1)
	}
}
