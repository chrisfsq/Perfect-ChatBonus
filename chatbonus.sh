echo -e "\e[1;35mChat bonus was started successfully \e[1;32m[...]\e[0m"
echo -e "\e[1;35mEncomendas de scripts \e[1;32m[discord #chrisffs]\e[0m"

tail -f -n0 /root/pwserver/logs/world2.chat | grep --line-buffered 'chl=0\|msg=IQBCAE8ATgBVAFMA' | while read LINE0
do    
    php pw_chatbonus.php sendBonus "${LINE0}"
done
