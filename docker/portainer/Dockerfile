# syntax=docker/dockerfile:1
# The standard Portainer image does not require a shell, curl, or wget.
FROM golang:1.25-alpine AS healthcheck-builder
WORKDIR /src
COPY healthcheck.go .
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /portainer-healthcheck healthcheck.go

FROM portainer/portainer-ce:lts
COPY --from=healthcheck-builder /portainer-healthcheck /portainer-healthcheck
