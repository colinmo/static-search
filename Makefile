build-onw:
    go build -ldflags="-w -s" -o siteindexer.exe .
    set GOOS=linux&&set GOARCH=amd64&&go build -ldflags="-w -s" -o siteindexer .