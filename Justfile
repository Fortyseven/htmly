run:
	php -S 0:8080
push:
	rsync -avz --delete --exclude='.git' --exclude='*.db' . scratch.network47.org:~/scratch.network47.org/
